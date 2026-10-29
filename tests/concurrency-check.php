<?php
if (wp_get_environment_type() !== 'local') { throw new RuntimeException('Local fixtures only.'); }
global $wpdb;
$run=$args[0] ?? ''; $fixture=get_option('paymento_edd_race_'.$run);
if (!$fixture) { throw new RuntimeException('Missing fixture.'); }
$assertions=0; $evidence=[];
foreach (['same','multi'] as $name) {
 $scenario=$fixture[$name]; $order=(int)$scenario['order'];
 // A worker that observed contention deliberately yields; the durable retry owns eventual processing.
 foreach ($scenario['rows'] as $row) { Paymento_EDD::instance()->process_row((int)$row); }
 $status=(string)edd_get_payment($order)->status;
 $events=$wpdb->get_results($wpdb->prepare('SELECT event,COUNT(*) AS count FROM '.$wpdb->prefix.'paymento_edd_race_events WHERE run_id=%s AND order_id=%d GROUP BY event',$run,$order),OBJECT_K);
 $completed=(int)($events['completion']->count ?? 0);
 $busy=(int)($events['lock_busy']->count ?? 0);
 $remaining=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.$wpdb->prefix.'paymento_edd_inbox i JOIN '.$wpdb->prefix.'paymento_edd_attempts a ON i.attempt_id=a.id WHERE a.order_id=%d AND i.processed_at IS NULL',$order));
 foreach ([
  'native order completed'=>in_array($status,['complete','publish'],true),
  'one durable native completion'=>$completed===1,
  'database contention observed'=>$busy>=1,
  'all durable rows processed'=>$remaining===0
 ] as $label=>$ok) {
  if (!$ok) { throw new RuntimeException('FAIL '.$name.': '.$label); }
  $assertions++; echo 'PASS '.$name.': '.$label.PHP_EOL;
 }
 $evidence[$name]=['native_status'=>$status,'completion_events'=>$completed,'lock_busy_events'=>$busy,'pending_rows'=>$remaining];
}
echo 'RACE_RESULT '.wp_json_encode(['run'=>$run,'assertions'=>$assertions,'scenarios'=>$evidence]).PHP_EOL;
