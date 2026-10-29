import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const cwd=process.env.EDD_COMPOSE_DIR || resolve(dirname(fileURLToPath(import.meta.url)),'..');
const base=process.env.EDD_TEST_URL || 'http://localhost:18080';
assert.equal(new URL(base).hostname,'localhost','Admin test requires isolated localhost fixture');
const checks=[];
function check(value,label){assert.ok(value,label);checks.push(label);console.log(`PASS ${label}`);}
const phpString=value=>`base64_decode('${Buffer.from(value).toString('base64')}')`;
function wp(code){
  const encoded=Buffer.from(code).toString('base64');
  const r=spawnSync('docker',['compose','run','--rm','cli','eval',`eval(base64_decode('${encoded}'));`],{cwd,encoding:'utf8',windowsHide:true,timeout:120000});
  if(r.status!==0)throw new Error(`Fixture CLI failed: ${r.stderr}`);
  const match=r.stdout.match(/PAYMENTO_JSON:(.+)/);if(!match)throw new Error('Missing fixture JSON');return JSON.parse(match[1]);
}
function client(){
 const cookies=new Map();
 return {
  cookies,
  async request(path,{method='GET',fields}={}){
   const headers={'connection':'close','accept-encoding':'identity'};
   if(cookies.size)headers.cookie=[...cookies].map(([k,v])=>`${k}=${v}`).join('; ');
   if(fields)headers['content-type']='application/x-www-form-urlencoded';
   const r=await fetch(new URL(path,base),{method,headers,body:fields?new URLSearchParams(fields):undefined,redirect:'manual',signal:AbortSignal.timeout(30000)});
   for(const cookie of r.headers.getSetCookie()){const pair=cookie.split(';')[0];const at=pair.indexOf('=');if(at>0)cookies.set(pair.slice(0,at),pair.slice(at+1));}
   return {status:r.status,location:r.headers.get('location'),body:await r.text()};
  }
 };
}
async function login(username,password){
 const c=client();await c.request('/wp-login.php');
 const result=await c.request('/wp-login.php',{method:'POST',fields:{log:username,pwd:password,'wp-submit':'Log In',redirect_to:`${base}/wp-admin/`,testcookie:'1'}});
 check([302,303].includes(result.status)&&[...c.cookies.keys()].some(k=>k.startsWith('wordpress_logged_in_')),`${username==='paymento-test-admin'?'Administrator':'Subscriber'} authenticates through native HTTP login`);
 return c;
}
function form(html,action){
 const block=[...html.matchAll(/<form\b[^>]*>[\s\S]*?<\/form>/gi)].map(m=>m[0]).find(s=>s.includes(`value="${action}"`));
 assert.ok(block,`Missing admin form ${action}`);
 const nonce=block.match(/name=["']_wpnonce["'][^>]*value=["']([^"']+)/)?.[1];
 assert.ok(nonce,'Missing native admin form nonce');return nonce;
}
const settingsPath='/wp-admin/options-general.php?page=paymento-edd';
function settings(){return wp(`echo 'PAYMENTO_JSON:'.wp_json_encode(get_option('paymento_edd_settings',array()));`);}
function same(left,right){return JSON.stringify(left)===JSON.stringify(right);}
const initial=wp(`if(wp_get_environment_type()!=='local')throw new Exception('Local fixture required');$s=get_option('paymento_edd_settings',null);$u=get_user_by('login','paymento-http-subscriber');if(!$u){$id=wp_create_user('paymento-http-subscriber','synthetic-subscriber-password','subscriber@example.invalid');$u=get_user_by('id',$id);}else{wp_set_password('synthetic-subscriber-password',$u->ID);}$u->set_role('subscriber');echo 'PAYMENTO_JSON:'.wp_json_encode(array('settings'=>$s,'subscriber'=>$u->ID,'wordpress'=>get_bloginfo('version'),'edd'=>EDD_VERSION));`);
const anonymous=client();
const apiKey='synthetic-dashboard-key-security-fixture';
const ipnSecret='synthetic-dashboard-secret-security-fixture';
let administrator;
try{
 const before=settings();
 const unauth=await anonymous.request('/wp-admin/admin-post.php',{method:'POST',fields:{action:'paymento_edd_save',environment:'beta',api_key:apiKey,ipn_secret:ipnSecret}});
 check([400,401,403,302].includes(unauth.status),'Unauthenticated settings submission rejected');
 check(same(settings(),before),'Unauthenticated submission leaves stored settings unchanged');
 const privatePage=await anonymous.request(settingsPath);
 check([302,303,401,403].includes(privatePage.status),'Unauthenticated settings page unavailable');
 const subscriber=await login('paymento-http-subscriber','synthetic-subscriber-password');
 const subPage=await subscriber.request(settingsPath);check(subPage.status===403,'Subscriber cannot open Paymento settings');
 const loggedIn=[...subscriber.cookies].find(([name])=>name.startsWith('wordpress_logged_in_'));
 const subNonce=wp(`wp_set_current_user(${initial.subscriber});$_COOKIE[LOGGED_IN_COOKIE]=${phpString(decodeURIComponent(loggedIn[1]))};echo 'PAYMENTO_JSON:'.wp_json_encode(array('nonce'=>wp_create_nonce('paymento_edd_save'),'can_manage'=>current_user_can('manage_options')));`);
 check(subNonce.can_manage===false,'Subscriber fixture has no manage_options capability');
 const denied=await subscriber.request('/wp-admin/admin-post.php',{method:'POST',fields:{action:'paymento_edd_save',_wpnonce:subNonce.nonce,environment:'beta',api_key:apiKey}});
 check(denied.status===403,'Subscriber with own session-valid nonce receives HTTP 403');
 check(same(settings(),before),'Subscriber submission leaves stored settings unchanged');
 administrator=await login('paymento-test-admin','synthetic-local-admin-password');
 const adminPage=await administrator.request(settingsPath);check(adminPage.status===200,'Administrator can open Paymento settings');
 const saveNonce=form(adminPage.body,'paymento_edd_save');const callbackNonce=form(adminPage.body,'paymento_edd_configure_ipn');
 check(Boolean(saveNonce)&&Boolean(callbackNonce),'Native administrator save/callback form nonces rendered');
 const missing=await administrator.request('/wp-admin/admin-post.php',{method:'POST',fields:{action:'paymento_edd_save',environment:'beta',api_key:apiKey}});
 check(missing.status===403,'Administrator missing nonce receives HTTP 403');
 check(same(settings(),before),'Missing nonce cannot change settings');
 const forged=await administrator.request('/wp-admin/admin-post.php',{method:'POST',fields:{action:'paymento_edd_save',_wpnonce:'forged-nonce',environment:'beta',api_key:apiKey}});
 check(forged.status===403,'Administrator forged nonce receives HTTP 403');
 check(same(settings(),before),'Forged nonce cannot change settings');
 const saved=await administrator.request('/wp-admin/admin-post.php',{method:'POST',fields:{action:'paymento_edd_save',_wpnonce:saveNonce,environment:'beta',api_key:apiKey,ipn_secret:ipnSecret}});
 check([302,303].includes(saved.status)&&saved.location?.includes('page=paymento-edd'),'Valid administrator form saves and redirects');
 const ciphertext=settings();
 check(ciphertext.environment==='beta','Selected environment persists');
 check(ciphertext.api_key!==apiKey&&ciphertext.ipn_secret!==ipnSecret&&!JSON.stringify(ciphertext).includes(apiKey)&&!JSON.stringify(ciphertext).includes(ipnSecret),'Dashboard credentials are not stored as plaintext');
 const encryption=wp(`$s=get_option('paymento_edd_settings');$ok=true;foreach(array('api_key'=>${phpString(apiKey)},'ipn_secret'=>${phpString(ipnSecret)}) as $k=>$expected){$d=base64_decode($s[$k],true);$p=openssl_decrypt(substr($d,28),'aes-256-gcm',hash('sha256',wp_salt('auth'),true),OPENSSL_RAW_DATA,substr($d,0,12),substr($d,12,16));$ok=$ok&&hash_equals($expected,$p);}echo 'PAYMENTO_JSON:'.wp_json_encode(array('authenticated_decryption_matches'=>$ok));`);
 check(encryption.authenticated_decryption_matches,'Both stored credentials decrypt correctly with authenticated encryption');
 const refreshed=await administrator.request(settingsPath);
 check(!refreshed.body.includes(apiKey)&&!refreshed.body.includes(ipnSecret),'Settings page never redisplays saved plaintext credentials');
 check(/name="api_key" value=""/.test(refreshed.body)&&/name="ipn_secret" value=""/.test(refreshed.body),'Credential password fields remain empty');
 const blank=await administrator.request('/wp-admin/admin-post.php',{method:'POST',fields:{action:'paymento_edd_save',_wpnonce:form(refreshed.body,'paymento_edd_save'),environment:'production',api_key:'',ipn_secret:''}});
 const retained=settings();
 check([302,303].includes(blank.status)&&retained.environment==='production','Valid subsequent environment change saves');
 check(retained.api_key===ciphertext.api_key&&retained.ipn_secret===ciphertext.ipn_secret,'Blank secret fields preserve existing encrypted credentials');
 const localCallback=await administrator.request('/wp-admin/admin-post.php',{method:'POST',fields:{action:'paymento_edd_configure_ipn',_wpnonce:callbackNonce,confirm:'1'}});
 check(localCallback.body.includes('Public HTTPS callback required.'),'Local HTTP callback configuration refused before remote settings API path');
 check(same(settings(),retained),'Refused callback configuration leaves plugin settings unchanged');
 const lifecycle=wp(`require_once ABSPATH.'wp-admin/includes/plugin.php';global $wpdb;$history=function()use($wpdb){return array('orders'=>$wpdb->get_results('SELECT id,status FROM '.$wpdb->prefix.'edd_orders ORDER BY id',ARRAY_A),'ledger'=>$wpdb->get_results('SELECT * FROM '.$wpdb->prefix.'paymento_edd_orders ORDER BY order_id',ARRAY_A),'attempts'=>$wpdb->get_results('SELECT id,order_id,token FROM '.$wpdb->prefix.'paymento_edd_attempts ORDER BY id',ARRAY_A),'inbox'=>$wpdb->get_results('SELECT id,attempt_id,processed_at FROM '.$wpdb->prefix.'paymento_edd_inbox ORDER BY id',ARRAY_A));};$before=hash('sha256',wp_json_encode($history()));deactivate_plugins('paymento-easy-digital-downloads/paymento-easy-digital-downloads.php');$cron_cleared=!wp_next_scheduled('paymento_edd_retry');$after_deactivate=hash('sha256',wp_json_encode($history()));$r=activate_plugin('paymento-easy-digital-downloads/paymento-easy-digital-downloads.php');if(is_wp_error($r))throw new Exception('Activation failed');echo 'PAYMENTO_JSON:'.wp_json_encode(array('cron_cleared'=>$cron_cleared,'history_after_deactivate'=>$before===$after_deactivate,'history_after_reactivate'=>$before===hash('sha256',wp_json_encode($history())),'cron_restored'=>(bool)wp_next_scheduled('paymento_edd_retry')));`);
 check(lifecycle.cron_cleared,'Deactivation clears Paymento retry cron');
 check(lifecycle.history_after_deactivate,'Deactivation preserves all native order/attempt/inbox history');
 check(lifecycle.history_after_reactivate,'Reactivation preserves all native order/attempt/inbox history');
 check(lifecycle.cron_restored,'Reactivation restores Paymento retry cron');
}finally{
 wp(`$s=json_decode(${phpString(JSON.stringify(initial.settings))},true);if($s===null){delete_option('paymento_edd_settings');}else{update_option('paymento_edd_settings',$s,false);}require_once ABSPATH.'wp-admin/includes/plugin.php';if(!is_plugin_active('paymento-easy-digital-downloads/paymento-easy-digital-downloads.php'))activate_plugin('paymento-easy-digital-downloads/paymento-easy-digital-downloads.php');echo 'PAYMENTO_JSON:'.wp_json_encode(array('restored'=>true));`);
}
check(same(settings(),initial.settings??[]),'Original plugin settings restored after security test');
console.log(JSON.stringify({result:'passed',kind:'native-http-admin-security',checks:checks.length,wordpress:initial.wordpress,edd:initial.edd,limitations:['Isolated synthetic credentials only','Local HTTP callback refusal tested; no real remote store callback modified']},null,2));
