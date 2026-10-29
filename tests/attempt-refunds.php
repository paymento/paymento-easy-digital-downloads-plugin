<?php
/** Real EDD + MariaDB regression: callbacks remain signed; only Paymento's HTTP service is simulated. */
if (wp_get_environment_type() !== 'local') { throw new RuntimeException('Local synthetic fixture only.'); }
global $wpdb;
$GLOBALS['paymento_attempt_refund_assertions'] = 0;
function ar_check(bool $ok, string $label): void {
 if (!$ok) { throw new RuntimeException('FAIL: '.$label); }
 $GLOBALS['paymento_attempt_refund_assertions']++; echo 'PASS: '.$label.PHP_EOL;
}
function ar_http(string $path, array $data): array {
 $r=wp_remote_post('http://mock:3000'.$path,['headers'=>['Content-Type'=>'application/json','Api-Key'=>'synthetic-api-key'],'body'=>wp_json_encode($data)]);
 if (is_wp_error($r) || wp_remote_retrieve_response_code($r)!==200) { throw new RuntimeException('Synthetic HTTP control failed.'); }
 return json_decode(wp_remote_retrieve_body($r),true);
}
function ar_new(): array {
 $download=wp_insert_post(['post_type'=>'download','post_status'=>'publish','post_title'=>'Attempt refund regression']);
 update_post_meta($download,'edd_price','12.50');
 $email='attempt-refund-'.strtolower(wp_generate_password(10,false)).'@example.invalid';
 return Paymento_EDD::instance()->create_payment([
  'gateway_nonce'=>wp_create_nonce('edd-gateway'),'price'=>'12.50','subtotal'=>'12.50','tax'=>0,'discount'=>0,
  'purchase_key'=>strtolower(wp_generate_password(32,false)),'user_email'=>$email,
  'downloads'=>[['id'=>$download,'quantity'=>1]],
  'user_info'=>['id'=>0,'email'=>$email,'first_name'=>'Attempt','last_name'=>'Refund','discount'=>'none'],
  'cart_details'=>[['id'=>$download,'name'=>'Attempt refund regression','item_number'=>['id'=>$download,'options'=>[]],'item_price'=>12.5,'price'=>12.5,'subtotal'=>12.5,'quantity'=>1,'tax'=>0,'discount'=>0]]
 ]);
}
function ar_second(array $payment): array {
 global $wpdb;
 $attempt=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'paymento_edd_attempts WHERE token=%s',$payment['token']),ARRAY_A);
 $r=ar_http('/v1/payment/request',['fiatAmount'=>'12.50','fiatCurrency'=>'USD','orderId'=>(string)$payment['order_id'],'returnUrl'=>home_url('/'),'additionalData'=>[['key'=>'fixture','value'=>wp_generate_uuid4()]]]);
 if (($r['success']??false)!==true || !is_string($r['body']??null)) { throw new RuntimeException('Second attempt request failed.'); }
 unset($attempt['id']); $attempt['token']=$r['body']; $attempt['terminal_status']=null; $attempt['review_status']=null;
 if ($wpdb->insert($wpdb->prefix.'paymento_edd_attempts',$attempt) !== 1) { throw new RuntimeException('Second attempt persistence failed.'); }
 return ['order_id'=>$payment['order_id'],'token'=>$r['body']];
}
function ar_attempt(array $payment): object {
 global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'paymento_edd_attempts WHERE token=%s',$payment['token']));
}
function ar_ledger(array $payment): object {
 global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'paymento_edd_orders WHERE order_id=%d',$payment['order_id']));
}
function ar_status(array $payment): string { return (string)edd_get_payment($payment['order_id'])->status; }
function ar_callback(array $payment,int $status): object {
 global $wpdb;
 ar_http('/__control',['token'=>$payment['token'],'status'=>$status]);
 $raw=wp_json_encode(['Token'=>$payment['token'],'OrderId'=>(string)$payment['order_id'],'OrderStatus'=>$status,'PaymentId'=>'synthetic-attempt-refund']);
 $req=new WP_REST_Request('POST','/paymento-edd/v1/ipn'); $req->set_body($raw);
 $req->set_header('X-HMAC-SHA256-Signature',strtoupper(hash_hmac('sha256',$raw,'synthetic-ipn-secret')));
 $r=rest_do_request($req);
 if ($r->get_status()!==200) { throw new RuntimeException('Signed callback was not accepted.'); }
 return $wpdb->get_row('SELECT * FROM '.$wpdb->prefix.'paymento_edd_inbox ORDER BY id DESC LIMIT 1');
}
$completions=[];
add_action('edd_update_payment_status',static function($id,$new,$old)use(&$completions):void {
 if(in_array($new,['complete','publish'],true)&&!in_array($old,['complete','publish'],true)){$completions[$id]=($completions[$id]??0)+1;}
},10,3);

ar_check(has_action('init',[Paymento_EDD::class,'maybe_upgrade'])!==false,'active plugins register automatic schema migration');
update_option('paymento_edd_schema_version',1,false);
$schemaFail=static function($sql)use($wpdb){return $sql==="SHOW COLUMNS FROM ".$wpdb->prefix."paymento_edd_attempts LIKE 'review_status'"?'INVALID_SCHEMA_COLUMN_FIXTURE':$sql;};
$wpdb->suppress_errors(true); add_filter('query',$schemaFail);
$migrationFailed=false;
try { Paymento_EDD::maybe_upgrade(); } catch (RuntimeException $error) { $migrationFailed=true; }
remove_filter('query',$schemaFail); $wpdb->suppress_errors(false);
ar_check($migrationFailed&&(int)get_option('paymento_edd_schema_version')===1,'schema column verification failure never advances migration version');
Paymento_EDD::maybe_upgrade();
ar_check((int)get_option('paymento_edd_schema_version')===2,'schema migration runs without activation/admin visit');

$a=ar_new(); $b=ar_second($a);
ar_callback($b,10);
ar_check(ar_status($a)==='pending'&&!ar_ledger($a)->winning_attempt,'unused attempt refund never promotes or refunds unpaid native order');
ar_check((int)ar_attempt($b)->terminal_status===10&&ar_attempt($a)->terminal_status===null,'refund terminal evidence belongs only to its token');
ar_callback($a,7);
ar_check(ar_status($a)==='complete'&&(int)ar_ledger($a)->winning_attempt===(int)ar_attempt($a)->id,'another verified token can legitimately win after unused token refund');
ar_callback($b,8);
ar_check(ar_status($a)==='complete'&&($completions[$a['order_id']]??0)===1,'refunded attempt late paid event cannot grant or duplicate access');

$a=ar_new(); $b=ar_second($a);
ar_callback($a,7); ar_callback($b,7);
ar_check(ar_status($a)==='complete'&&($completions[$a['order_id']]??0)===1,'two successful tokens complete native order once');
ar_check(ar_attempt($b)->review_status==='duplicate_payment','second financial payment is flagged for merchant review');
ar_callback($b,10);
ar_check(ar_status($a)==='complete'&&(int)ar_ledger($a)->terminal_refund===0,'refunding losing paid token preserves winning paid order');
ar_check((int)ar_attempt($b)->terminal_status===10&&ar_attempt($a)->terminal_status===null,'losing refund does not mark winner refunded');
ar_callback($b,8);
ar_check(ar_status($a)==='complete'&&($completions[$a['order_id']]??0)===1,'late paid event from refunded loser cannot change winner');
$wpdb->delete($wpdb->prefix.'paymento_edd_orders',['order_id'=>$a['order_id']]);
ar_callback($a,8);
ar_check((int)ar_ledger($a)->winning_attempt===(int)ar_attempt($a)->id&&($completions[$a['order_id']]??0)===1,'upgrade backfills paid winner from native transaction and durable verification');
ar_callback($a,10);
ar_check(ar_status($a)==='refunded'&&(int)ar_ledger($a)->terminal_refund===1,'winning token refund terminally refunds native order');
$c=ar_second($a); ar_callback($c,7);
ar_check(ar_status($a)==='refunded'&&($completions[$a['order_id']]??0)===1,'new paid token cannot resurrect parent after winner refund');

$a=ar_new(); $b=ar_second($a);
$inject=static function($id,$status):void {if($status===8){throw new RuntimeException('Synthetic winner crash');}};
add_action('paymento_edd_after_verified',$inject,10,2);
$cached=ar_callback($a,7);
remove_action('paymento_edd_after_verified',$inject,10);
ar_check($cached->processed_at===null&&ar_status($a)==='pending'&&(int)ar_ledger($a)->winning_attempt===(int)ar_attempt($a)->id,'winner survives crash before native completion');
ar_callback($b,10);
ar_check(ar_status($a)==='pending'&&(int)ar_ledger($a)->terminal_refund===0,'unused token refund cannot revoke reserved verified winner');
ar_callback($a,10); Paymento_EDD::instance()->process_row((int)$cached->id);
ar_check(ar_status($a)==='refunded'&&($completions[$a['order_id']]??0)===0,'winning refund beats cached paid work before native completion');

$a=ar_new(); ar_callback($a,7);
edd_update_payment_status($a['order_id'],'refunded'); ar_callback($a,8);
ar_check(ar_status($a)==='refunded'&&(int)ar_ledger($a)->terminal_refund===1,'native administrator refund is terminal on callback replay');

$a=ar_new();
$before=json_decode(wp_remote_retrieve_body(wp_remote_get('http://mock:3000/__state')),true)['verifies'];
$fail=static function($sql)use($wpdb) {return str_starts_with($sql,'INSERT INTO')&&str_contains($sql,$wpdb->prefix.'paymento_edd_orders')?'INVALID_LEDGER_INSERT_FIXTURE':$sql;};
$wpdb->suppress_errors(true); add_filter('query',$fail); $failed=ar_callback($a,7); remove_filter('query',$fail); $wpdb->suppress_errors(false);
$after=json_decode(wp_remote_retrieve_body(wp_remote_get('http://mock:3000/__state')),true)['verifies'];
ar_check($failed->processed_at===null&&ar_status($a)==='pending'&&$before===$after,'ledger insert failure leaves durable retry before verify or native changes');
Paymento_EDD::instance()->process_row((int)$failed->id);
ar_check(ar_status($a)==='complete','ledger failure recovers through retained callback');
echo wp_json_encode(['result'=>'passed','assertions'=>$GLOBALS['paymento_attempt_refund_assertions'],'schema'=>get_option('paymento_edd_schema_version')]).PHP_EOL;
