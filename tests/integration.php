<?php
/** Runs inside an actual WordPress + EDD + MariaDB container, with HTTP Paymento fixture. */
if (wp_get_environment_type() !== 'local') { throw new RuntimeException('Local synthetic fixture only.'); }
global $wpdb;
$GLOBALS['paymento_test_assertions'] = 0;
function check($condition, string $label): void {
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    $GLOBALS['paymento_test_assertions']++;
    echo 'PASS: ' . $label . PHP_EOL;
}
function control(array $data): void {
    $response = wp_remote_post('http://mock:3000/__control', array('headers' => array('Content-Type'=>'application/json'), 'body'=>wp_json_encode($data)));
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { throw new RuntimeException('Mock control failed.'); }
}
function mock_state(): array {
    return json_decode(wp_remote_retrieve_body(wp_remote_get('http://mock:3000/__state')), true);
}
function status(int $id): string {
    clean_post_cache($id);
    return (string) edd_get_payment($id)->status;
}
function purchase_data(): array {
    static $download;
    if (!$download) {
        $download = wp_insert_post(array('post_type'=>'download','post_status'=>'publish','post_title'=>'Synthetic Integration Download'));
        update_post_meta($download, 'edd_price', '12.50');
    }
    $email = 'fixture-' . wp_generate_password(8, false) . '@example.invalid';
    return array(
        'gateway_nonce'=>wp_create_nonce('edd-gateway'), 'price'=>'12.50', 'subtotal'=>'12.50', 'tax'=>0, 'discount'=>0,
        'purchase_key'=>strtolower(wp_generate_password(32, false)), 'user_email'=>$email,
        'downloads'=>array(array('id'=>$download, 'quantity'=>1)),
        'user_info'=>array('id'=>0, 'email'=>$email, 'first_name'=>'Synthetic', 'last_name'=>'Fixture', 'discount'=>'none'),
        'cart_details'=>array(array('id'=>$download, 'name'=>'Synthetic Integration Download', 'item_number'=>array('id'=>$download, 'options'=>array()), 'item_price'=>12.5, 'price'=>12.5, 'subtotal'=>12.5, 'quantity'=>1, 'tax'=>0, 'discount'=>0)),
    );
}
function new_payment(): array { return Paymento_EDD::instance()->create_payment(purchase_data()); }
function callback(array $payment, int $remoteStatus, string $method = 'POST', ?string $signatureOverride = null, array $replace = array()): WP_REST_Response {
    control(array('token'=>$payment['token'], 'status'=>$remoteStatus));
    $raw = wp_json_encode(array_merge(array('Token'=>$payment['token'], 'PaymentId'=>'synthetic', 'OrderId'=>(string)$payment['order_id'], 'OrderStatus'=>$remoteStatus, 'AdditionalData'=>array()), $replace));
    $request = new WP_REST_Request($method, '/paymento-edd/v1/ipn');
    $request->set_body($raw);
    $request->set_header('Content-Type', 'application/json');
    $request->set_header('X-HMAC-SHA256-Signature', $signatureOverride ?? strtoupper(hash_hmac('sha256', $raw, 'synthetic-ipn-secret')));
    return rest_do_request($request);
}
function pending_rows(): int {
    global $wpdb;
    return (int)$wpdb->get_var('SELECT COUNT(*) FROM '.$wpdb->prefix.'paymento_edd_inbox WHERE processed_at IS NULL');
}
function last_row(): object {
    global $wpdb;
    return $wpdb->get_row('SELECT * FROM '.$wpdb->prefix.'paymento_edd_inbox ORDER BY id DESC LIMIT 1');
}

control(array('reset'=>true));
// Do not truncate financial tables: every run adds isolated synthetic orders/tokens.
check(isset(edd_get_payment_gateways()['paymento']), 'native EDD gateway registered');
$bad = purchase_data(); $bad['gateway_nonce'] = 'forged';
try { Paymento_EDD::instance()->create_payment($bad); check(false, 'nonce rejection'); }
catch (RuntimeException $error) { check($error->getMessage() === 'Invalid checkout nonce.', 'forged checkout nonce rejected'); }

control(array('mode'=>'refuse'));
try { new_payment(); check(false, 'business refusal'); }
catch (RuntimeException $error) { check(str_contains($error->getMessage(), 'declined'), 'HTTP 200 business refusal never creates redirect'); }
control(array('mode'=>'normal'));
$payment = new_payment();
check(status($payment['order_id']) === 'pending', 'real EDD order starts pending');
check(str_contains($payment['redirect'], $payment['token']), 'persisted token used in redirect');
$attempt = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'paymento_edd_attempts WHERE token=%s', $payment['token']));
check($attempt && (string)$attempt->order_id === (string)$payment['order_id'] && $attempt->amount === '12.50', 'trusted total and token mapping persisted');

$taxedData = purchase_data();
$taxedData['price'] = '14.50'; $taxedData['tax'] = '2.00';
$taxedData['cart_details'][0]['tax'] = 2.00; $taxedData['cart_details'][0]['price'] = 14.50;
$taxed = Paymento_EDD::instance()->create_payment($taxedData);
check((float)edd_get_payment($taxed['order_id'])->total === 14.5 && (float)edd_get_payment($taxed['order_id'])->tax === 2.0, 'native tax and trusted grand total preserved');
$discountData = purchase_data();
$discountData['price'] = '10.00'; $discountData['discount'] = '2.50';
$discountData['cart_details'][0]['discount'] = 2.50; $discountData['cart_details'][0]['price'] = 10.00;
$discounted = Paymento_EDD::instance()->create_payment($discountData);
check((float)edd_get_payment($discounted['order_id'])->total === 10.0, 'discounted native grand total preserved');

$before = mock_state()['verifies'];
check(callback($payment, 7, 'POST', str_repeat('0',64))->get_status() === 401, 'wrong raw signature rejected');
check(mock_state()['verifies'] === $before, 'bad signature never verifies');
check(callback($payment, 7, 'POST', null, array('OrderId'=>'foreign-order'))->get_status() === 404, 'callback order mismatch rejected');
$unknown = new WP_REST_Request('POST','/paymento-edd/v1/ipn');
$unknownRaw = wp_json_encode(array('Token'=>'unknown-token','OrderId'=>'999999'));
$unknown->set_body($unknownRaw); $unknown->set_header('X-HMAC-SHA256-Signature',strtoupper(hash_hmac('sha256',$unknownRaw,'synthetic-ipn-secret')));
check(rest_do_request($unknown)->get_status() === 404, 'unknown signed token rejected');
check(mock_state()['verifies'] === $before, 'unknown and mismatched callbacks never verify');

$noSignature = new WP_REST_Request('POST', '/paymento-edd/v1/ipn');
$noSignature->set_body(wp_json_encode(array('Token'=>$payment['token'],'OrderId'=>(string)$payment['order_id'])));
check(rest_do_request($noSignature)->get_status() === 401, 'missing signature rejected');
$malformed = new WP_REST_Request('POST','/paymento-edd/v1/ipn');
$malformed->set_body('{not-json');
$malformed->set_header('X-HMAC-SHA256-Signature',strtoupper(hash_hmac('sha256','{not-json','synthetic-ipn-secret')));
check(rest_do_request($malformed)->get_status() === 400, 'signed malformed JSON rejected');
$oversizedRaw = wp_json_encode(array('Token'=>$payment['token'],'OrderId'=>(string)$payment['order_id'],'padding'=>str_repeat('a',66000)));
$oversized = new WP_REST_Request('POST','/paymento-edd/v1/ipn');
$oversized->set_body($oversizedRaw);
$oversized->set_header('X-HMAC-SHA256-Signature',strtoupper(hash_hmac('sha256',$oversizedRaw,'synthetic-ipn-secret')));
check(rest_do_request($oversized)->get_status() === 413, 'oversized authenticated body rejected');
$originalRaw = wp_json_encode(array('Token'=>$payment['token'],'OrderId'=>(string)$payment['order_id']));
$mutated = new WP_REST_Request('POST','/paymento-edd/v1/ipn');
$mutated->set_body($originalRaw.' ');
$mutated->set_header('X-HMAC-SHA256-Signature',strtoupper(hash_hmac('sha256',$originalRaw,'synthetic-ipn-secret')));
check(rest_do_request($mutated)->get_status() === 401, 'raw whitespace mutation invalidates signature');
foreach (array(0,1,2,5,6,9) as $unpaidStatus) {
    check(callback($payment,$unpaidStatus)->get_status() === 200 && status($payment['order_id']) === 'pending', 'unpaid/reserved status '.$unpaidStatus.' cannot grant access');
}

check(callback($payment,3,'PUT')->get_status() === 200, 'valid signed PUT accepted');
check(status($payment['order_id']) === 'pending', 'success=false confirming response remains unpaid');
check(last_row()->processed_at !== null && (int)last_row()->verified_status === 3, 'each callback has durable verified result');

$completionEvents = 0;
add_action('edd_update_payment_status', function($id,$new,$old) use (&$completionEvents,$payment) {
    if ((int)$id === $payment['order_id'] && in_array($new,array('publish','complete'),true)) { $completionEvents++; }
},10,3);
control(array('mode'=>'outage'));
check(callback($payment,7)->get_status() === 200, 'API outage still acknowledges durably stored callback');
$outageRow = last_row();
check($outageRow->processed_at === null && status($payment['order_id']) === 'pending', 'outage retains retryable work without fulfillment');
control(array('mode'=>'normal'));
Paymento_EDD::instance()->process_row((int)$outageRow->id);
check(in_array(status($payment['order_id']),array('publish','complete'),true), 'retry completes real EDD order after verify');
check(callback($payment,8)->get_status() === 200, 'duplicate paid callback accepted as separate receipt');
check($completionEvents === 1, 'duplicate paid callback triggers one native completion');
check(callback($payment,4)->get_status() === 200 && in_array(status($payment['order_id']),array('publish','complete'),true), 'late timeout cannot unpay completed order');
check(callback($payment,10)->get_status() === 200 && status($payment['order_id']) === 'refunded', 'verified full refund recorded natively');
check(callback($payment,8)->get_status() === 200 && status($payment['order_id']) === 'refunded', 'late paid event cannot resurrect refunded order');

$crash = new_payment();
$inject = function($rowId,$verifiedStatus) { if ($verifiedStatus === 8) { throw new RuntimeException('synthetic crash after verified result'); } };
add_action('paymento_edd_after_verified',$inject,10,2);
check(callback($crash,7)->get_status() === 200, 'crash after verify is durably acknowledged');
$crashRow = last_row();
check($crashRow->processed_at === null && (int)$crashRow->verified_status === 8 && status($crash['order_id']) === 'pending', 'verified result survives before native completion');
remove_action('paymento_edd_after_verified',$inject,10);
$beforeRetry = mock_state()['verifies'];
Paymento_EDD::instance()->process_row((int)$crashRow->id);
check(in_array(status($crash['order_id']),array('publish','complete'),true) && mock_state()['verifies'] === $beforeRetry, 'restart recovers cached verification without another callback');

$reordered = new_payment();
add_action('paymento_edd_after_verified',$inject,10,2);
callback($reordered,7); $cachedPaidRow = last_row();
remove_action('paymento_edd_after_verified',$inject,10);
callback($reordered,10);
Paymento_EDD::instance()->process_row((int)$cachedPaidRow->id);
check(status($reordered['order_id']) === 'refunded', 'refund terminal marker beats older cached paid work');

$wrongToken = new_payment();
control(array('token'=>$wrongToken['token'],'verifyToken'=>'other-token'));
callback($wrongToken,7);
check(last_row()->processed_at === null && status($wrongToken['order_id']) === 'pending', 'wrong verified token remains quarantined and unpaid');
$wrongOrder = new_payment();
control(array('token'=>$wrongOrder['token'],'verifyOrderId'=>'other-order'));
callback($wrongOrder,7);
check(last_row()->processed_at === null && status($wrongOrder['order_id']) === 'pending', 'wrong verified order remains quarantined and unpaid');

$changed = new_payment();
$model = edd_get_payment($changed['order_id']); $model->total = 99.00; $model->save();
$before = mock_state()['verifies'];
callback($changed,7);
check(last_row()->processed_at === null && status($changed['order_id']) === 'pending' && mock_state()['verifies'] === $before, 'edited native total fails closed before verify');

$storage = new_payment();
$before = mock_state()['verifies'];
$failInsert = function($sql) use ($wpdb) {
    if (str_starts_with($sql,'INSERT INTO') && str_contains($sql,$wpdb->prefix.'paymento_edd_inbox')) { return 'INVALID_SYNTHETIC_SQL'; }
    return $sql;
};
$wpdb->suppress_errors(true);
add_filter('query',$failInsert);
$storageResponse = callback($storage,7);
remove_filter('query',$failInsert);
$wpdb->suppress_errors(false);
check($storageResponse->get_status() === 503 && mock_state()['verifies'] === $before, 'inbox insert failure returns 503 before mutating verify');

$expiring = new_payment();
$sent = current(array_filter(mock_state()['payments'], fn($p) => $p['token'] === $expiring['token']))['tokenExpiryDate'] ?? '';
check(abs(strtotime($sent) - (time() + 24 * HOUR_IN_SECONDS)) < 120, 'payment request carries default 24-hour token expiry');
$wpdb->update($wpdb->prefix.'paymento_edd_attempts', array('created_at'=>gmdate('Y-m-d H:i:s', time() - 26 * HOUR_IN_SECONDS)), array('token'=>$expiring['token']));
$fresh = new_payment();
Paymento_EDD::instance()->expire_orders();
check(status($expiring['order_id']) === 'abandoned' && status($fresh['order_id']) === 'pending', 'expired pending order abandoned; fresh order untouched');
check(callback($expiring,7)->get_status() === 200 && in_array(status($expiring['order_id']),array('publish','complete'),true), 'late paid callback completes abandoned order');

echo wp_json_encode(array('result'=>'passed','assertions'=>$GLOBALS['paymento_test_assertions'],'wordpress'=>get_bloginfo('version'),'edd'=>EDD_VERSION,'php'=>PHP_VERSION,'pending_quarantine_rows'=>pending_rows())) . PHP_EOL;
