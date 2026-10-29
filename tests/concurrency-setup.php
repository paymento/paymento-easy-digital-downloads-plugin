<?php
if (wp_get_environment_type() !== 'local') { throw new RuntimeException('Local fixtures only.'); }
global $wpdb;
$run = $args[0] ?? '';
if (!preg_match('/^[a-z0-9-]{8,80}$/D', $run)) { throw new RuntimeException('Invalid run identifier.'); }
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
$audit = $wpdb->prefix . 'paymento_edd_race_events';
dbDelta("CREATE TABLE $audit (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 run_id varchar(80) NOT NULL,
 event varchar(40) NOT NULL,
 order_id bigint(20) unsigned NOT NULL,
 inbox_id bigint(20) unsigned NOT NULL DEFAULT 0,
 created_at datetime NOT NULL,
 PRIMARY KEY  (id),
 KEY run_id (run_id)
) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ';');
function race_control(array $input): void {
 $r = wp_remote_post('http://mock:3000/__control', ['headers'=>['Content-Type'=>'application/json'], 'body'=>wp_json_encode($input)]);
 if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) { throw new RuntimeException('Token control failed.'); }
}
function race_seed(string $label): array {
 $download = wp_insert_post(['post_type'=>'download','post_status'=>'publish','post_title'=>'Concurrency '.$label]);
 update_post_meta($download, 'edd_price', '12.50');
 $email = 'race-'.strtolower(wp_generate_password(10,false)).'@example.invalid';
 return Paymento_EDD::instance()->create_payment([
  'gateway_nonce'=>wp_create_nonce('edd-gateway'),'price'=>'12.50','subtotal'=>'12.50','tax'=>0,'discount'=>0,
  'purchase_key'=>strtolower(wp_generate_password(32,false)),'user_email'=>$email,
  'downloads'=>[['id'=>$download,'quantity'=>1]],
  'user_info'=>['id'=>0,'email'=>$email,'first_name'=>'Concurrency','last_name'=>'Fixture','discount'=>'none'],
  'cart_details'=>[['id'=>$download,'name'=>'Concurrency fixture','item_number'=>['id'=>$download,'options'=>[]],'item_price'=>12.5,'price'=>12.5,'subtotal'=>12.5,'quantity'=>1,'tax'=>0,'discount'=>0]]
 ]);
}
function race_inbox(string $token): int {
 global $wpdb;
 $attempt = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'paymento_edd_attempts WHERE token=%s',$token));
 if (!$attempt) { throw new RuntimeException('Missing attempt.'); }
 $payload = wp_json_encode(['Token'=>$token,'OrderId'=>(string)$attempt->order_id,'OrderStatus'=>7,'PaymentId'=>'synthetic-race']);
 $ok = $wpdb->insert($wpdb->prefix.'paymento_edd_inbox',['attempt_id'=>$attempt->id,'token'=>$token,'payload'=>$payload,'received_at'=>gmdate('Y-m-d H:i:s')]);
 if ($ok !== 1) { throw new RuntimeException('Inbox seed failed.'); }
 return (int)$wpdb->insert_id;
}
$same = race_seed('same row');
race_control(['token'=>$same['token'],'status'=>7]);
$sameRow = race_inbox($same['token']);
$multi = race_seed('two tokens');
$firstAttempt = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'paymento_edd_attempts WHERE token=%s',$multi['token']), ARRAY_A);
$r = wp_remote_post('http://mock:3000/v1/payment/request',[
 'headers'=>['Api-Key'=>'synthetic-api-key','Content-Type'=>'application/json'],
 'body'=>wp_json_encode(['fiatAmount'=>'12.50','fiatCurrency'=>'USD','orderId'=>(string)$multi['order_id'],'returnUrl'=>home_url('/'),'additionalData'=>[['key'=>'race','value'=>$run]]])
]);
$decoded = is_wp_error($r) ? null : json_decode(wp_remote_retrieve_body($r),true);
if (wp_remote_retrieve_response_code($r) !== 200 || ($decoded['success'] ?? false) !== true || !is_string($decoded['body'] ?? null)) { throw new RuntimeException('Second-token fixture failed.'); }
$secondToken = $decoded['body'];
unset($firstAttempt['id']); $firstAttempt['token']=$secondToken;
if ($wpdb->insert($wpdb->prefix.'paymento_edd_attempts',$firstAttempt) !== 1) { throw new RuntimeException('Second attempt insert failed.'); }
race_control(['token'=>$multi['token'],'status'=>7]);
race_control(['token'=>$secondToken,'status'=>7]);
$firstRow = race_inbox($multi['token']);
$secondRow = race_inbox($secondToken);
$fixture = ['run'=>$run,'same'=>['order'=>$same['order_id'],'rows'=>[$sameRow]],'multi'=>['order'=>$multi['order_id'],'rows'=>[$firstRow,$secondRow]]];
update_option('paymento_edd_race_'.$run,$fixture,false);
echo 'RACE_FIXTURE '.wp_json_encode($fixture).PHP_EOL;
