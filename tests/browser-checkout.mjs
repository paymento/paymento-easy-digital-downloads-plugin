import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { get as httpGet } from 'node:http';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

// Native guest HTTP checkout against the isolated Compose fixture, with synthetic payments only.
const cwd = process.env.EDD_COMPOSE_DIR || resolve(dirname(fileURLToPath(import.meta.url)), '..');
const base = process.env.EDD_TEST_URL || 'http://localhost:18080';
const mock = process.env.PAYMENTO_MOCK_URL || 'http://localhost:18081';
assert.equal(new URL(base).hostname, 'localhost', 'Fixture must use localhost');
assert.equal(new URL(mock).hostname, 'localhost', 'Mock must use localhost');
const checks = [];
function check(condition, label) { assert.ok(condition, label); checks.push(label); console.log(`PASS ${label}`); }
function wp(code) {
  const encoded = Buffer.from(code).toString('base64');
  const result = spawnSync('docker', ['compose', 'run', '--rm', 'cli', 'eval', `eval(base64_decode('${encoded}'));`], {cwd, encoding:'utf8', timeout:120000, windowsHide:true});
  if (result.status !== 0) throw new Error(`WP fixture operation failed: ${result.stderr || result.stdout}`);
  const match = result.stdout.match(/PAYMENTO_JSON:(.+)/);
  if (!match) throw new Error(`Missing fixture JSON: ${result.stdout}`);
  return JSON.parse(match[1]);
}
const jar = new Map();
function decode(value) { return value.replace(/&amp;/g,'&').replace(/&#0*39;/g,"'").replace(/&quot;/g,'"').replace(/&#0*38;/g,'&'); }
function attrs(tag) {
  const result = {};
  for (const m of tag.matchAll(/([\w:-]+)\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/g)) result[m[1].toLowerCase()] = decode(m[2] ?? m[3] ?? m[4]);
  return result;
}
async function request(url, options = {}, follow = true) {
  let current = new URL(url,base);
  for (let n=0;n<8;n++) {
    const headers = new Headers(options.headers || {});
    headers.set('connection','close');
    headers.set('accept-encoding','identity');
    if (current.origin === new URL(base).origin && jar.size) headers.set('cookie',[...jar].map(([k,v])=>`${k}=${v}`).join('; '));
    const response = await fetch(current,{...options,headers,redirect:'manual',signal:AbortSignal.timeout(30000)});
    if (current.origin === new URL(base).origin) {
      for (const cookie of response.headers.getSetCookie()) {
        const [pair] = cookie.split(';'); const at=pair.indexOf('=');
        if (at>0) jar.set(pair.slice(0,at),pair.slice(at+1));
      }
    }
    const body=await response.text();
    if (!follow || ![301,302,303,307,308].includes(response.status)) return {response,body,url:current.href};
    current=new URL(response.headers.get('location'),current);
    if (current.origin !== new URL(base).origin) return {response,body,url:current.href};
    if ([301,302,303].includes(response.status)) options={};
  }
  throw new Error('Redirect loop');
}
async function control(data) {
  const r=await fetch(`${mock}/__control`,{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify(data)});
  assert.equal(r.status,200); return r.json();
}
async function mockState() { return (await fetch(`${mock}/__state`)).json(); }

const fixture=wp(`
if (!function_exists('edd_get_option') || !class_exists('Paymento_EDD')) throw new Exception('EDD and Paymento must be active');
$id=wp_insert_post(array('post_type'=>'download','post_title'=>'HTTP checkout smoke fixture','post_status'=>'publish'));
update_post_meta($id,'edd_price','12.34');
$upload=wp_upload_dir(); wp_mkdir_p($upload['basedir'].'/edd');
file_put_contents($upload['basedir'].'/edd/paymento-http-fixture.txt','PAYMENTO_SYNTHETIC_DOWNLOAD_OK');
update_post_meta($id,'edd_download_files',array(array('name'=>'Smoke fixture','file'=>$upload['baseurl'].'/edd/paymento-http-fixture.txt','condition'=>'all')));
echo 'PAYMENTO_JSON:'.wp_json_encode(array('product_id'=>$id,'checkout'=>edd_get_checkout_uri(),'success'=>edd_get_success_page_uri(),'edd_version'=>EDD_VERSION,'wp_version'=>get_bloginfo('version')));
`);
check(fixture.product_id>0,'Native purchasable download fixture created');
console.log(`Fixture versions: WordPress ${fixture.wp_version}; EDD ${fixture.edd_version}`);
await control({mode:'normal'});
const add=new URL(fixture.checkout); add.searchParams.set('edd_action','add_to_cart'); add.searchParams.set('download_id',String(fixture.product_id)); add.searchParams.set('payment-mode','paymento');
await request(add.href);
const checkoutURL=new URL(fixture.checkout); checkoutURL.searchParams.set('payment-mode','paymento');
const page=await request(checkoutURL.href);
check(page.response.status===200,'Guest classic checkout HTTP 200');
const formTag=[...page.body.matchAll(/<form\b[^>]*>/gi)].map(m=>m[0]).find(t=>attrs(t).id==='edd_purchase_form');
check(Boolean(formTag),'Native EDD purchase form rendered');
const start=page.body.indexOf(formTag); const end=page.body.indexOf('</form>',start);
let formHTML=page.body.slice(start,end);
if (!formHTML.includes('edd-process-checkout-nonce')) {
  const gatewayInput=[...page.body.matchAll(/<input\b[^>]*>/gi)].map(m=>attrs(m[0])).find(a=>a.id==='edd-gateway-paymento');
  check(Boolean(gatewayInput?.['data-paymento-nonce']),'Native gateway-selection CSRF nonce rendered');
  const ajaxFields=new URLSearchParams({action:'edd_load_gateway',edd_payment_mode:'paymento',nonce:gatewayInput['data-paymento-nonce']});
  const gatewayFields=await request(new URL('/wp-admin/admin-ajax.php',base),{method:'POST',headers:{'content-type':'application/x-www-form-urlencoded',referer:checkoutURL.href},body:ajaxFields.toString()});
  check(gatewayFields.response.status===200,'Native gateway fields loaded via nonce-protected AJAX');
  formHTML+=gatewayFields.body;
}
const fields=new URLSearchParams();
for (const match of formHTML.matchAll(/<input\b[^>]*>/gi)) {
  const a=attrs(match[0]);
  if (!a.name || /\bdisabled\b/i.test(match[0])) continue;
  if (['checkbox','radio'].includes(a.type) && !/\bchecked\b/i.test(match[0])) continue;
  if (['button','reset'].includes(a.type)) continue;
  fields.append(a.name,a.value || '');
}
check(Boolean(fields.get('edd-process-checkout-nonce')),'CSRF nonce obtained from guest checkout HTML');
fields.set('edd_email',`http-smoke-${Date.now()}@example.test`);
fields.set('edd_first','HTTP'); fields.set('edd_last','Smoke');
fields.set('edd_action','purchase'); fields.set('edd-gateway','paymento'); fields.set('payment-mode','paymento');
fields.set('billing_country','US'); fields.set('card_state','NY'); fields.set('card_zip','10001');
const action=new URL(attrs(formTag).action || checkoutURL.href,base);
const posted=await request(action.href,{method:'POST',headers:{'content-type':'application/x-www-form-urlencoded',referer:checkoutURL.href},body:fields.toString()},false);
const location=posted.response.headers.get('location');
if (![302,303].includes(posted.response.status) || !location?.startsWith(`${mock}/gateway?token=`)) {
  const messages=[...posted.body.matchAll(/<(?:p|span|div)[^>]*class=["'][^"']*edd_error[^"']*["'][^>]*>([\s\S]*?)<\//gi)].map(m=>m[1].replace(/<[^>]*>/g,' '));
  throw new Error(`Checkout did not redirect to mock: HTTP ${posted.response.status}, location=${location}; errors=${messages.join(' | ')}; text=${posted.body.replace(/<[^>]*>/g,' ').replace(/\s+/g,' ').slice(-1800)}`);
}
check(true,'Real guest form POST reaches Paymento hosted redirect');
const token=new URL(location).searchParams.get('token');
const state=await mockState(); const attempt=state.payments.find(p=>p.token===token);
check(attempt?.amount==='12.34','Payment request uses trusted catalog total');
const orderId=Number(attempt.orderId);
function inspect() {
 return wp(`$p=edd_get_payment(${orderId}); echo 'PAYMENTO_JSON:'.wp_json_encode(array('status'=>$p->status,'gateway'=>$p->gateway,'items'=>count($p->cart_details),'download_url'=>edd_get_download_file_url($p->key,$p->email,0,${fixture.product_id})));`);
}
const pending=inspect();
check(pending.status==='pending' && pending.gateway==='paymento' && pending.items===1,'Native EDD order pending with purchased item');
check(Boolean(pending.download_url),'Native signed download URL generated for access test');
const blocked=await request(pending.download_url);
check(blocked.response.status===403 && !blocked.body.includes('PAYMENTO_SYNTHETIC_DOWNLOAD_OK'),'Native HTTP download denied before verified payment');
const forged=new URL(fixture.success); forged.searchParams.set('token',token); forged.searchParams.set('orderId',String(orderId)); forged.searchParams.set('status','8');
await request(forged.href);
check(inspect().status==='pending','Unsigned browser return cannot mark native order paid');
await control({token,status:7});
const payload=JSON.stringify({Token:token,PaymentId:'synthetic-http-smoke',OrderId:String(orderId),OrderStatus:7,AdditionalData:[]});
const ipn=new URL('/?rest_route=/paymento-edd/v1/ipn',base);
const bad=await fetch(ipn,{method:'POST',headers:{'content-type':'application/json','X-HMAC-SHA256-Signature':'0'.repeat(64)},body:payload});
check(bad.status===401,'Invalid webhook signature rejected over HTTP');
check(inspect().status==='pending','Rejected webhook does not complete native order');
const signature=createHmac('sha256','synthetic-ipn-secret').update(payload).digest('hex').toUpperCase();
const verified=await fetch(ipn,{method:'POST',headers:{'content-type':'application/json','X-HMAC-SHA256-Signature':signature},body:payload});
check(verified.status===200,'Valid signed synthetic webhook accepted over HTTP');
wp(`Paymento_EDD::instance()->process_inbox(); echo 'PAYMENTO_JSON:'.wp_json_encode(array('processed'=>true));`);
const paid=inspect();
check(['complete','publish'].includes(paid.status),'Durable processor verification completes native EDD order');
// CLI work can outlive Apache's five-second keep-alive. Use a fresh socket for the
// attachment rather than reusing an idle Undici connection; no retry hides a failure.
const downloaded=await new Promise((resolve,reject)=>{
  const req=httpGet(paid.download_url,{agent:false,headers:{'accept-encoding':'identity',cookie:[...jar].map(([k,v])=>`${k}=${v}`).join('; ')}},response=>{
    let body='';response.setEncoding('utf8');response.on('data',chunk=>body+=chunk);
    response.on('end',()=>resolve({status:response.statusCode,body}));response.on('error',reject);
  });
  req.setTimeout(30000,()=>req.destroy(new Error('Download timed out')));req.on('error',reject);
});
check(downloaded.status===200 && downloaded.body==='PAYMENTO_SYNTHETIC_DOWNLOAD_OK','Native HTTP download granted after verified synthetic payment');
console.log(JSON.stringify({result:'passed',kind:'guest-classic-checkout-http-synthetic',checks:checks.length,orderId,productId:fixture.product_id,wordpress:fixture.wp_version,edd:fixture.edd_version,limitations:['No browser JavaScript execution or visual QA','No real Paymento network or funded transaction','Classic shortcode checkout only']},null,2));
