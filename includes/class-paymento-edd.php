<?php
defined('ABSPATH') || exit;

/** Native EDD adapter. Paymento is authoritative only after signed durable processing. */
final class Paymento_EDD {
    private static ?self $instance = null;
    private bool $registered = false;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function register_hooks(): void {
        if ($this->registered) { return; }
        $this->registered = true;
        add_filter('edd_payment_gateways', array($this, 'gateways'));
        add_action('edd_gateway_paymento', array($this, 'process_purchase'));
        add_action('edd_paymento_cc_form', '__return_false');
        add_action('rest_api_init', array($this, 'routes'));
        add_filter('cron_schedules', array($this, 'cron_schedules'));
        add_action('paymento_edd_retry', array($this, 'process_inbox'));
        add_action('paymento_edd_expire', array($this, 'expire_orders'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_post_paymento_edd_save', array($this, 'save_settings'));
        add_action('admin_post_paymento_edd_configure_ipn', array($this, 'configure_ipn'));
        add_action('admin_notices', array($this, 'admin_notice'));
        add_action('init', array(self::class, 'maybe_upgrade'), 1);
        add_shortcode('paymento_edd_status', array($this, 'return_message'));
    }

    public function cron_schedules(array $schedules): array {
        $schedules['paymento_edd_minute'] = array('interval' => 60, 'display' => 'Paymento every minute');
        return $schedules;
    }

    public static function maybe_upgrade(): void {
        if ((int) get_option('paymento_edd_schema_version', 0) < 2) { self::activate(); }
    }

    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $attempts = $wpdb->prefix . 'paymento_edd_attempts';
        $inbox = $wpdb->prefix . 'paymento_edd_inbox';
        $orders = $wpdb->prefix . 'paymento_edd_orders';
        dbDelta("CREATE TABLE $attempts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            token varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            api_base varchar(255) NOT NULL,
            checkout_base varchar(255) NOT NULL,
            amount varchar(64) NOT NULL,
            currency varchar(12) NOT NULL,
            terminal_status int NULL,
            review_status varchar(40) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY order_id (order_id)
        ) ENGINE=InnoDB $charset;");
        dbDelta("CREATE TABLE $inbox (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attempt_id bigint(20) unsigned NOT NULL,
            token varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            payload longtext NOT NULL,
            verified_status int NULL,
            received_at datetime NOT NULL,
            processed_at datetime NULL,
            attempts int unsigned NOT NULL DEFAULT 0,
            last_error varchar(255) NULL,
            PRIMARY KEY  (id),
            KEY pending (processed_at,received_at),
            KEY attempt_id (attempt_id)
        ) ENGINE=InnoDB $charset;");
        dbDelta("CREATE TABLE $orders (
            order_id bigint(20) unsigned NOT NULL,
            winning_attempt bigint(20) unsigned NULL,
            terminal_refund tinyint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (order_id)
        ) ENGINE=InnoDB $charset;");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $attempts)) !== $attempts ||
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders)) !== $orders ||
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $inbox)) !== $inbox ||
            $wpdb->get_var("SHOW COLUMNS FROM $attempts LIKE 'review_status'") !== 'review_status' ||
            $wpdb->get_var("SHOW COLUMNS FROM $orders LIKE 'winning_attempt'") !== 'winning_attempt') {
            throw new RuntimeException('Paymento database installation failed.');
        }
        if ((int) get_option('paymento_edd_schema_version', 0) < 2) {
            // Pre-1.0 builds copied a refund to every attempt. Reconstruct token-local
            // terminal evidence from durable authenticated/verified callback records.
            if ($wpdb->query("UPDATE $attempts a SET terminal_status=CASE WHEN EXISTS (
                SELECT 1 FROM $inbox i WHERE i.attempt_id=a.id AND i.verified_status=10
            ) THEN 10 ELSE NULL END") === false) {
                throw new RuntimeException('Paymento refund migration failed.');
            }
            update_option('paymento_edd_schema_version', 2, false);
            if ((int) get_option('paymento_edd_schema_version') !== 2) { throw new RuntimeException('Paymento schema version did not persist.'); }
        }
        self::instance()->register_hooks();
        if (!wp_next_scheduled('paymento_edd_retry')) {
            wp_schedule_event(time() + 60, 'paymento_edd_minute', 'paymento_edd_retry');
        }
        if (!wp_next_scheduled('paymento_edd_expire')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'paymento_edd_expire');
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('paymento_edd_expire');
        wp_clear_scheduled_hook('paymento_edd_retry');
    }

    private function expiry_hours(): int {
        $hours = (int) (get_option('paymento_edd_settings', array())['expiry_hours'] ?? 24);
        return max(1, min(168, $hours)); // Paymento accepts token expiry up to 7 days.
    }

    private function order_lock(int $orderId): string {
        global $wpdb;
        return 'pedd_' . substr(hash('sha256', $wpdb->prefix . ':' . $orderId), 0, 48);
    }

    /** Pending orders whose newest Paymento attempt has expired become abandoned; a later paid callback still completes them. */
    public function expire_orders(): void {
        global $wpdb;
        if (!function_exists('edd_get_payment')) { return; }
        // The extra hour covers a coin payment window opened just before the token expired.
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($this->expiry_hours() + 1) * HOUR_IN_SECONDS);
        $ids = $wpdb->get_col($wpdb->prepare('SELECT a.order_id FROM ' . $wpdb->prefix . 'paymento_edd_attempts a JOIN ' . $wpdb->prefix . "edd_orders o ON o.id=a.order_id
            WHERE o.status='pending' AND o.gateway='paymento' GROUP BY a.order_id HAVING MAX(a.created_at) < %s LIMIT 100", $cutoff));
        foreach ($ids as $id) {
            $lock = $this->order_lock((int) $id);
            // A busy order is being processed by a callback; the next run re-checks it.
            if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') { continue; }
            try {
                $payment = edd_get_payment((int) $id);
                if ($payment && (string) $payment->status === 'pending') { edd_update_payment_status((int) $id, 'abandoned'); }
            } finally {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
    }

    public function gateways(array $gateways): array {
        $gateways['paymento'] = array('admin_label' => 'Paymento', 'checkout_label' => 'Crypto with Paymento');
        return $gateways;
    }

    public function routes(): void {
        register_rest_route('paymento-edd/v1', '/ipn', array(
            'methods' => array('POST', 'PUT'),
            'callback' => array($this, 'receive_ipn'),
            'permission_callback' => '__return_true',
        ));
    }

    /** Public testable checkout entry point; still requires the EDD gateway nonce. */
    public function create_payment(array $purchaseData): array {
        global $wpdb;
        if (!function_exists('edd_insert_payment') || !function_exists('edd_get_payment')) {
            throw new RuntimeException('Easy Digital Downloads is required.');
        }
        if (!wp_verify_nonce((string) ($purchaseData['gateway_nonce'] ?? ''), 'edd-gateway')) {
            throw new RuntimeException('Invalid checkout nonce.');
        }
        $apiKey = $this->credential('api_key');
        if ($apiKey === '' || $this->credential('ipn_secret') === '') {
            throw new RuntimeException('Paymento credentials are missing.');
        }
        $amount = $this->amount($purchaseData['price'] ?? '');
        $currency = strtoupper((string) edd_get_currency());
        $bases = $this->bases();
        $paymentData = array_intersect_key($purchaseData, array_flip(array(
            'date', 'fees', 'tax', 'subtotal', 'discount', 'discounts', 'user_id', 'parent',
        )));
        $paymentData = array_merge($paymentData, array(
            'price' => $amount,
            'user_email' => sanitize_email($purchaseData['user_email'] ?? ''),
            'purchase_key' => $purchaseData['purchase_key'] ?? '',
            'downloads' => $purchaseData['downloads'] ?? array(),
            'user_info' => $purchaseData['user_info'] ?? array(),
            'cart_details' => $purchaseData['cart_details'] ?? array(),
            'status' => 'pending',
            'gateway' => 'paymento',
            'currency' => $currency,
        ));
        $orderId = (int) edd_insert_payment($paymentData);
        if (!$orderId) { throw new RuntimeException('Unable to persist pending EDD order.'); }
        // Read EDD's persisted total, not a browser query or post_data field.
        $payment = edd_get_payment($orderId);
        if (!$payment) { throw new RuntimeException('Pending EDD order cannot be read.'); }
        $amount = $this->amount($payment->total);
        if (!empty($payment->currency)) { $currency = strtoupper((string) $payment->currency); }
        $returnUrl = add_query_arg('paymento_return', '1', function_exists('edd_get_success_page_uri') ? edd_get_success_page_uri() : home_url('/'));
        $response = $this->api('POST', '/v1/payment/request', array(
            'fiatAmount' => $amount,
            'fiatCurrency' => $currency,
            'orderId' => (string) $orderId,
            'returnUrl' => $returnUrl,
            'emailAddress' => sanitize_email($purchaseData['user_email'] ?? ''),
            'tokenExpiryDate' => gmdate('Y-m-d\TH:i:s\Z', time() + $this->expiry_hours() * HOUR_IN_SECONDS),
        ), $bases['api']);
        $token = $response['body'] ?? null;
        if (($response['success'] ?? null) !== true || !is_string($token) || !$this->valid_token($token)) {
            throw new RuntimeException('Paymento declined payment initialization.');
        }
        $inserted = $wpdb->insert($wpdb->prefix . 'paymento_edd_attempts', array(
            'order_id' => $orderId, 'token' => $token, 'api_base' => $bases['api'],
            'checkout_base' => $bases['checkout'], 'amount' => $amount, 'currency' => $currency,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ));
        if ($inserted === false) { throw new RuntimeException('Unable to persist Paymento token; checkout was not redirected.'); }
        return array('order_id' => $orderId, 'token' => $token, 'redirect' => add_query_arg('token', $token, $bases['checkout']));
    }

    private function amount(mixed $input): string {
        // EDD compatibility models expose numeric totals; canonicalize using EDD precision.
        if (!is_scalar($input) || !preg_match('/^\d+(?:\.\d+)?$/D', (string) $input)) {
            throw new RuntimeException('Invalid payment amount.');
        }
        $precision = function_exists('edd_currency_decimal_filter') ? (int) edd_currency_decimal_filter() : 2;
        $precision = max(0, min(8, $precision));
        $parts = explode('.', (string) $input, 2);
        $integer = ltrim($parts[0], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = $parts[1] ?? '';
        if (strlen($fraction) > $precision && trim(substr($fraction, $precision), '0') !== '') {
            throw new RuntimeException('Amount exceeds currency precision.');
        }
        $result = $integer . ($precision ? '.' . str_pad(substr($fraction, 0, $precision), $precision, '0') : '');
        if (trim(str_replace('.', '', $result), '0') === '') { throw new RuntimeException('Payment amount must be positive.'); }
        return $result;
    }

    public function process_purchase(array $purchaseData): void {
        try {
            $result = $this->create_payment($purchaseData);
            edd_empty_cart();
            wp_redirect($result['redirect'], 303, 'Paymento EDD');
            exit;
        } catch (Throwable $error) {
            edd_set_error('paymento_initialization', __('Unable to start crypto payment. Please try again or contact the store.', 'paymento-edd'));
            edd_send_back_to_checkout('?payment-mode=paymento');
        }
    }

    private function valid_token(string $token): bool { return (bool) preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $token); }

    private function comparable_amount(mixed $value): string {
        if (!is_scalar($value) || !preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', (string) $value)) {
            throw new RuntimeException('Invalid verified settlement amount.');
        }
        $parts = explode('.', (string) $value, 2);
        $whole = ltrim($parts[0], '0');
        $fraction = rtrim($parts[1] ?? '', '0');
        return ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);
    }

    public function receive_ipn(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $raw = $request->get_body();
        $signature = $request->get_header('X-HMAC-SHA256-Signature');
        $secret = $this->credential('ipn_secret');
        if ($secret === '' || !is_string($signature) ||
            !preg_match('/^[A-Fa-f0-9]{64}$/D', $signature) ||
            !hash_equals(strtoupper(hash_hmac('sha256', $raw, $secret)), strtoupper($signature))) {
            return new WP_REST_Response(array('error' => 'invalid_signature'), 401);
        }
        if (strlen($raw) > 65536) { return new WP_REST_Response(array('error' => 'payload_too_large'), 413); }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['Token'], $data['OrderId']) ||
            !is_string($data['Token']) || !$this->valid_token($data['Token']) ||
            !is_string($data['OrderId'])) {
            return new WP_REST_Response(array('error' => 'invalid_payload'), 400);
        }
        $attempt = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $wpdb->prefix . 'paymento_edd_attempts WHERE token=%s', $data['Token']));
        if (!$attempt || (string) $attempt->order_id !== $data['OrderId']) {
            return new WP_REST_Response(array('error' => 'unknown_payment'), 404);
        }
        if ($wpdb->insert($wpdb->prefix . 'paymento_edd_inbox', array(
            'attempt_id' => $attempt->id, 'token' => $attempt->token,
            'payload' => $raw, 'received_at' => gmdate('Y-m-d H:i:s'),
        )) === false) {
            return new WP_REST_Response(array('error' => 'inbox_unavailable'), 503);
        }
        $id = (int) $wpdb->insert_id;
        // 2xx once stored: Verify may already have moved Paid to Approve, which ends Paymento's retries.
        try { $this->process_row($id); } catch (Throwable $error) { /* Durable worker retries. */ }
        return new WP_REST_Response(array('accepted' => true), 200);
    }

    /** Called only under the shared order lock; native transaction identity is an upgrade/recovery anchor. */
    private function order_ledger(int $orderId, object $payment): object {
        global $wpdb;
        $orders = $wpdb->prefix . 'paymento_edd_orders';
        $attempts = $wpdb->prefix . 'paymento_edd_attempts';
        $ledger = $wpdb->get_row($wpdb->prepare("SELECT * FROM $orders WHERE order_id=%d", $orderId));
        if ($wpdb->last_error !== '') { throw new RuntimeException('Payment ledger read failed.'); }
        if (!$ledger) {
            if ($wpdb->insert($orders, array('order_id' => $orderId)) === false) { throw new RuntimeException('Payment ledger creation failed.'); }
            $ledger = (object) array('order_id' => $orderId, 'winning_attempt' => null, 'terminal_refund' => 0);
        }
        $transaction = (string) edd_get_payment_transaction_id($orderId);
        if ($wpdb->last_error !== '') { throw new RuntimeException('Native transaction lookup failed.'); }
        $nativeWinner = 0;
        if (preg_match('/^paymento-attempt-([1-9][0-9]*)$/D', $transaction, $match)) {
            $nativeWinner = (int) $match[1];
            $proof = $wpdb->get_var($wpdb->prepare("SELECT a.id FROM $attempts a WHERE a.id=%d AND a.order_id=%d AND EXISTS (
                SELECT 1 FROM " . $wpdb->prefix . "paymento_edd_inbox i WHERE i.attempt_id=a.id AND i.verified_status IN (7,8,10)
            )", $nativeWinner, $orderId));
            if ($wpdb->last_error !== '' || !$proof) { throw new RuntimeException('Native winning attempt cannot be reconciled.'); }
        } elseif (in_array((string) $payment->status, array('complete', 'publish'), true)) {
            throw new RuntimeException('Paid native order has no trusted winning attempt.');
        }
        if ($nativeWinner && $ledger->winning_attempt && (int) $ledger->winning_attempt !== $nativeWinner) {
            throw new RuntimeException('Native transaction conflicts with winning attempt.');
        }
        $changes = array();
        if ($nativeWinner && !$ledger->winning_attempt) { $changes['winning_attempt'] = $nativeWinner; }
        if ((string) $payment->status === 'refunded') { $changes['terminal_refund'] = 1; }
        if ($changes) {
            if ($wpdb->update($orders, $changes, array('order_id' => $orderId)) === false) { throw new RuntimeException('Payment ledger reconciliation failed.'); }
            foreach ($changes as $key => $value) { $ledger->$key = $value; }
        }
        return $ledger;
    }

    /** A pending row is the only entry point to the mutating verify endpoint. */
    public function process_row(int $id): void {
        global $wpdb;
        $inbox = $wpdb->prefix . 'paymento_edd_inbox';
        $attempts = $wpdb->prefix . 'paymento_edd_attempts';
        $row = $wpdb->get_row($wpdb->prepare("SELECT i.*, a.order_id, a.api_base, a.amount, a.currency FROM $inbox i JOIN $attempts a ON a.id=i.attempt_id AND a.token=i.token WHERE i.id=%d", $id));
        if (!$row || $row->processed_at !== null) { return; }
        $lock = $this->order_lock((int) $row->order_id);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') {
            throw new RuntimeException('Order processing is busy.');
        }
        try {
            $row = $wpdb->get_row($wpdb->prepare("SELECT i.*, a.order_id, a.api_base, a.amount, a.currency FROM $inbox i JOIN $attempts a ON a.id=i.attempt_id AND a.token=i.token WHERE i.id=%d", $id));
            if (!$row || $row->processed_at !== null) { return; }
            if ($wpdb->query($wpdb->prepare("UPDATE $inbox SET attempts=attempts+1 WHERE id=%d", $id)) === false) {
                throw new RuntimeException('Inbox update failed.');
            }
            $payment = edd_get_payment((int) $row->order_id);
            if (!$payment || $payment->gateway !== 'paymento') { throw new RuntimeException('Mapped EDD order is unavailable.'); }
            if ($this->comparable_amount($payment->total) !== $this->comparable_amount($row->amount) ||
                strtoupper((string) $payment->currency) !== strtoupper($row->currency)) {
                throw new RuntimeException('EDD order amount or currency changed after payment initiation.');
            }
            $ledger = $this->order_ledger((int) $row->order_id, $payment);
            $fresh = $row->verified_status === null;
            if ($row->verified_status === null) {
                $verified = $this->api('POST', '/v1/payment/verify', array('token' => $row->token), $row->api_base);
                $body = $verified['body'] ?? null;
                if (!is_array($body) || !isset($body['token'], $body['orderId'], $body['orderStatus']) ||
                    !is_string($body['token']) || !hash_equals($row->token, $body['token']) ||
                    !is_string($body['orderId']) || $body['orderId'] !== (string) $row->order_id ||
                    !is_int($body['orderStatus']) || $body['orderStatus'] < 0 || $body['orderStatus'] > 10) {
                    throw new RuntimeException('Paymento verification identity mismatch.');
                }
                if (isset($body['settlement']['requestedFiatAmount']) &&
                    $this->comparable_amount($body['settlement']['requestedFiatAmount']) !== $this->comparable_amount($row->amount)) {
                    throw new RuntimeException('Verified settlement amount mismatch.');
                }
                $status = $body['orderStatus'];
                if ($wpdb->update($inbox, array('verified_status' => $status), array('id' => $id)) === false) {
                    throw new RuntimeException('Verified outcome persistence failed.');
                }
            } else { $status = (int) $row->verified_status; }
            if ($status === 10 && $wpdb->update($attempts, array('terminal_status' => 10, 'review_status' => null), array('id' => $row->attempt_id)) === false) {
                throw new RuntimeException('Refund terminal state did not persist.');
            }
            $attemptRefunded = (int) $wpdb->get_var($wpdb->prepare("SELECT terminal_status FROM $attempts WHERE id=%d", $row->attempt_id)) === 10;
            if ($wpdb->last_error !== '') { throw new RuntimeException('Terminal state lookup failed.'); }
            $orders = $wpdb->prefix . 'paymento_edd_orders';
            if (in_array($status, array(7, 8), true) && !$attemptRefunded && !$ledger->terminal_refund && !$ledger->winning_attempt) {
                if ($wpdb->update($orders, array('winning_attempt' => $row->attempt_id), array('order_id' => $row->order_id)) === false) {
                    throw new RuntimeException('Winning attempt did not persist.');
                }
                $ledger->winning_attempt = (int) $row->attempt_id;
            }
            if ($attemptRefunded && (int) $ledger->winning_attempt === (int) $row->attempt_id) {
                if ($wpdb->update($orders, array('terminal_refund' => 1), array('order_id' => $row->order_id)) === false) { throw new RuntimeException('Order refund terminal state did not persist.'); }
                $ledger->terminal_refund = 1;
            }
            // Winner and verified result survive a crash before native mutation.
            if ($fresh) { do_action('paymento_edd_after_verified', $id, $status); }
            $payment = edd_get_payment((int) $row->order_id);
            $current = (string) $payment->status;
            if ($current === 'refunded' && !$ledger->terminal_refund) {
                if ($wpdb->update($orders, array('terminal_refund' => 1), array('order_id' => $row->order_id)) === false) { throw new RuntimeException('Native refund reconciliation failed.'); }
                $ledger->terminal_refund = 1;
            }
            if ($ledger->terminal_refund && $current !== 'refunded') {
                edd_update_payment_status((int) $row->order_id, 'refunded');
                $updated = edd_get_payment((int) $row->order_id);
                if (!$updated || (string) $updated->status !== 'refunded') { throw new RuntimeException('EDD refund status did not persist.'); }
            } elseif (in_array($status, array(7, 8), true) && !$attemptRefunded && !$ledger->terminal_refund && (int) $ledger->winning_attempt === (int) $row->attempt_id && !in_array($current, array('complete', 'publish', 'refunded'), true)) {
                edd_set_payment_transaction_id((int) $row->order_id, 'paymento-attempt-' . $row->attempt_id);
                if ((string) edd_get_payment_transaction_id((int) $row->order_id) !== 'paymento-attempt-' . $row->attempt_id || $wpdb->last_error !== '') { throw new RuntimeException('Native transaction identity did not persist.'); }
                edd_update_payment_status((int) $row->order_id, 'complete');
                $updated = edd_get_payment((int) $row->order_id);
                if (!$updated || !in_array((string) $updated->status, array('complete', 'publish'), true)) {
                    throw new RuntimeException('EDD completion did not persist.');
                }
            } elseif (in_array($status, array(7, 8), true) && !$attemptRefunded && !$ledger->terminal_refund && $ledger->winning_attempt && (int) $ledger->winning_attempt !== (int) $row->attempt_id) {
                if ($wpdb->update($attempts, array('review_status' => 'duplicate_payment'), array('id' => $row->attempt_id)) === false) { throw new RuntimeException('Duplicate payment review did not persist.'); }
            }
            // Negative/pending statuses do not cancel the order: another attempt may still pay.
            if ($wpdb->update($inbox, array('processed_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null), array('id' => $id)) === false) {
                throw new RuntimeException('Inbox completion did not persist.');
            }
        } catch (Throwable $error) {
            // No remote response, payload, merchant credential or token in operational error.
            $wpdb->update($inbox, array('last_error' => 'Processing failed; retry pending.'), array('id' => $id));
            throw $error;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    public function process_inbox(): void {
        global $wpdb;
        if (!function_exists('edd_get_payment')) { return; }
        // Retry-count ordering rotates persistent failures behind untouched/recoverable work.
        $ids = $wpdb->get_col('SELECT id FROM ' . $wpdb->prefix . 'paymento_edd_inbox WHERE processed_at IS NULL ORDER BY attempts ASC, id ASC LIMIT 100');
        $started = microtime(true);
        foreach ($ids as $id) {
            if (microtime(true) - $started >= 5) { break; }
            try { $this->process_row((int) $id); } catch (Throwable $error) { /* Retain pending row. */ }
        }
    }

    private function api(string $method, string $path, ?array $body = null, ?string $base = null): array {
        $base ??= $this->bases()['api'];
        $args = array('method' => $method, 'timeout' => 20, 'redirection' => 0,
            'headers' => array('Api-Key' => $this->credential('api_key'), 'Content-Type' => 'application/json', 'Accept' => 'application/json'));
        if ($body !== null) { $args['body'] = wp_json_encode($body); }
        $response = wp_remote_request(rtrim($base, '/') . $path, $args);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) < 200 || wp_remote_retrieve_response_code($response) >= 300) {
            throw new RuntimeException('Paymento API unavailable.');
        }
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($decoded) || !array_key_exists('success', $decoded) || !is_bool($decoded['success'])) {
            throw new RuntimeException('Invalid Paymento response envelope.');
        }
        return $decoded;
    }

    private function bases(): array {
        $settings = get_option('paymento_edd_settings', array());
        $beta = ($settings['environment'] ?? 'production') === 'beta';
        $api = defined('PAYMENTO_EDD_API_BASE') ? PAYMENTO_EDD_API_BASE : ($beta ? 'https://beta-api.paymento.io' : 'https://api.paymento.io');
        $checkout = defined('PAYMENTO_EDD_CHECKOUT_BASE') ? PAYMENTO_EDD_CHECKOUT_BASE : ($beta ? 'https://beta.paymento.io/gateway' : 'https://app.paymento.io/gateway');
        return array('api' => rtrim($api, '/'), 'checkout' => $checkout);
    }

    private function credential(string $key): string {
        $constant = $key === 'api_key' ? 'PAYMENTO_EDD_API_KEY' : 'PAYMENTO_EDD_IPN_SECRET';
        if (defined($constant)) { return (string) constant($constant); }
        $settings = get_option('paymento_edd_settings', array());
        $encrypted = $settings[$key] ?? '';
        if (!is_string($encrypted) || $encrypted === '') { return ''; }
        $data = base64_decode($encrypted, true);
        if ($data === false || strlen($data) < 29 || !function_exists('openssl_decrypt')) { return ''; }
        $plain = openssl_decrypt(substr($data, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16));
        return $plain === false ? '' : $plain;
    }

    private function encrypt(string $value): string {
        if (!function_exists('openssl_encrypt')) { throw new RuntimeException('OpenSSL is required for dashboard credential storage.'); }
        $iv = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($value, 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) { throw new RuntimeException('Credential encryption failed.'); }
        return base64_encode($iv . $tag . $encrypted);
    }

    public function return_message(): string {
        return '<p>' . esc_html__('Payment confirmation may take time. View your EDD receipt or contact the merchant if your downloads are not available yet.', 'paymento-edd') . '</p>';
    }

    public function admin_menu(): void {
        add_options_page('Paymento EDD', 'Paymento EDD', 'manage_options', 'paymento-edd', array($this, 'settings_page'));
    }

    public function settings_page(): void {
        if (!current_user_can('manage_options')) { return; }
        $settings = get_option('paymento_edd_settings', array());
        echo '<div class="wrap"><h1>Paymento for Easy Digital Downloads</h1><p>Save your Paymento API key and IPN secret, configure the callback, then enable Paymento under Downloads &gt; Settings &gt; Payments.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="paymento_edd_save">';
        wp_nonce_field('paymento_edd_save');
        echo '<p><label>Environment <select name="environment"><option value="production"' . selected($settings['environment'] ?? '', 'production', false) . '>Production</option><option value="beta"' . selected($settings['environment'] ?? '', 'beta', false) . '>Beta</option></select></label></p>';
        echo '<p>Deployment constants override settings. Leave secret fields blank to retain the current encrypted value. Define PAYMENTO_EDD_API_KEY and PAYMENTO_EDD_IPN_SECRET in wp-config.php for managed deployments.</p>';
        echo '<p><label>API key <input autocomplete="new-password" type="password" name="api_key" value=""></label></p><p><label>IPN secret <input autocomplete="new-password" type="password" name="ipn_secret" value=""></label></p>';
        echo '<p><label>Payment expiry (hours) <input type="number" name="expiry_hours" min="1" max="168" value="' . esc_attr((string) $this->expiry_hours()) . '"></label> Unpaid orders are marked abandoned one hour after this. A late payment still completes the order.</p>';
        submit_button('Save settings');
        echo '</form><h2>Explicit callback configuration</h2><p>Callback: <code>' . esc_html(rest_url('paymento-edd/v1/ipn')) . '</code>. This action changes the Paymento store callback. A different existing URL will be refused. Use a separate Paymento store for each integration.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="paymento_edd_configure_ipn">';
        wp_nonce_field('paymento_edd_configure_ipn');
        echo '<label><input type="checkbox" name="confirm" value="1" required> Configure this callback for this Paymento store.</label>';
        submit_button('Configure Paymento callback');
        echo '</form><p>Run WP cron from a system scheduler every minute. Do not rotate WordPress authentication salts without re-entering dashboard-managed credentials.</p></div>';
    }

    public function save_settings(): void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', '', array('response' => 403)); }
        check_admin_referer('paymento_edd_save');
        $settings = get_option('paymento_edd_settings', array());
        $settings['environment'] = isset($_POST['environment']) && $_POST['environment'] === 'beta' ? 'beta' : 'production';
        if (isset($_POST['expiry_hours']) && is_string($_POST['expiry_hours']) && ctype_digit($_POST['expiry_hours'])) {
            $settings['expiry_hours'] = max(1, min(168, (int) $_POST['expiry_hours']));
        }
        foreach (array('api_key', 'ipn_secret') as $key) {
            if (isset($_POST[$key]) && is_string($_POST[$key]) && $_POST[$key] !== '') {
                $settings[$key] = $this->encrypt(wp_unslash($_POST[$key]));
            }
        }
        update_option('paymento_edd_settings', $settings, false);
        wp_safe_redirect(admin_url('options-general.php?page=paymento-edd&updated=1')); // Core shows "Settings saved."
        exit;
    }

    public function configure_ipn(): void {
        if (!current_user_can('manage_options')) { wp_die('Forbidden', '', array('response' => 403)); }
        check_admin_referer('paymento_edd_configure_ipn');
        if (($_POST['confirm'] ?? '') !== '1') { wp_die('Explicit confirmation required.'); }
        try {
            $url = rest_url('paymento-edd/v1/ipn');
            if (wp_parse_url($url, PHP_URL_SCHEME) !== 'https') { throw new RuntimeException('Public HTTPS callback required.'); }
            $existing = $this->api('GET', '/v1/payment/settings');
            if (($existing['success'] ?? false) !== true || !is_array($existing['body'] ?? null)) {
                throw new RuntimeException('Unable to inspect current Paymento callback settings.');
            }
            $old = (string) ($existing['body']['ipN_URL'] ?? '');
            if ($old !== '' && $old !== $url) { throw new RuntimeException('A different callback already exists. Use a separate Paymento store.'); }
            $saved = $this->api('POST', '/v1/payment/settings', array('ipN_URL' => $url, 'ipN_Method' => 1));
            if (($saved['success'] ?? false) !== true) { throw new RuntimeException('Paymento callback configuration was declined.'); }
        } catch (Throwable $error) { wp_die(esc_html($error->getMessage())); }
        wp_safe_redirect(admin_url('options-general.php?page=paymento-edd&updated=1')); // Core shows "Settings saved."
        exit;
    }

    public function admin_notice(): void {
        if (!current_user_can('manage_options')) { return; }
        if (!function_exists('edd_insert_payment')) {
            echo '<div class="notice notice-error"><p>Paymento requires Easy Digital Downloads.</p></div>';
            return;
        }
        global $wpdb;
        $old = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'paymento_edd_inbox WHERE processed_at IS NULL AND received_at < %s', gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)));
        if ($old > 0) { echo '<div class="notice notice-error"><p>' . esc_html(sprintf('Paymento: %d callbacks have been pending for over one hour. Check the retry worker.', $old)) . '</p></div>'; }
        $duplicates = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . "paymento_edd_attempts WHERE review_status='duplicate_payment'");
        if ($duplicates > 0) { echo '<div class="notice notice-error"><p>' . esc_html(sprintf('Paymento: %d additional payments require merchant review. The original paid order remains fulfilled; inspect Paymento attempts before refunding.', $duplicates)) . '</p></div>'; }
    }
}
