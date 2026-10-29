<?php
if (wp_get_environment_type() !== 'local') { throw new RuntimeException('Local fixtures only.'); }
global $wpdb;
$run = $args[0] ?? ''; $row = (int)($args[1] ?? 0); $hold = ($args[2] ?? '') === 'hold';
$fixture = get_option('paymento_edd_race_'.$run);
$rows = $fixture ? array_merge($fixture['same']['rows'],$fixture['multi']['rows']) : [];
if (!in_array($row,$rows,true)) { throw new RuntimeException('Unknown race row.'); }
$audit = $wpdb->prefix.'paymento_edd_race_events';
$record = static function(string $event,int $order,int $inbox) use($wpdb,$audit,$run): void {
 if ($wpdb->insert($audit,['run_id'=>$run,'event'=>$event,'order_id'=>$order,'inbox_id'=>$inbox,'created_at'=>gmdate('Y-m-d H:i:s')]) !== 1) { throw new RuntimeException('Audit insert failed.'); }
};
$mappedOrder = (int)$wpdb->get_var($wpdb->prepare('SELECT a.order_id FROM '.$wpdb->prefix.'paymento_edd_inbox i JOIN '.$wpdb->prefix.'paymento_edd_attempts a ON i.attempt_id=a.id WHERE i.id=%d',$row));
add_action('edd_update_payment_status',static function($id,$new,$old) use($mappedOrder,$record,$row): void {
 if ((int)$id === $mappedOrder && in_array($new,['complete','publish'],true) && !in_array($old,['complete','publish'],true)) { $record('completion',(int)$id,$row); }
},10,3);
if ($hold) {
 add_action('paymento_edd_after_verified',static function($id,$status) use($row,$mappedOrder,$record): void {
  if ((int)$id !== $row) { return; }
  $record('lock_held',$mappedOrder,$row);
  echo "LOCK_HELD\n"; flush();
  usleep(10000000);
 },10,2);
}
try {
 Paymento_EDD::instance()->process_row($row);
 $record('processed',$mappedOrder,$row);
 echo "WORKER_PROCESSED\n";
} catch (Throwable $error) {
 if ($error->getMessage() !== 'Order processing is busy.') { throw $error; }
 $record('lock_busy',$mappedOrder,$row);
 echo "WORKER_LOCK_BUSY\n";
}
