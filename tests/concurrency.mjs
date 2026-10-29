import {spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const cwd=fileURLToPath(new URL('..',import.meta.url));
const prefix='/var/www/html/wp-content/plugins/paymento-easy-digital-downloads/tests/';
const run='race-'+Date.now()+'-'+Math.random().toString(16).slice(2,10);
function launch(file,args=[],onChunk=()=>{}) {
 const child=spawn('docker',['compose','run','--rm','-T','cli','eval-file',prefix+file,...args],{cwd,stdio:['ignore','pipe','pipe'],windowsHide:true});
 let output='';
 child.stdout.on('data',chunk=>{const text=chunk.toString();output+=text;onChunk(text);});
 child.stderr.on('data',chunk=>{output+=chunk.toString();});
 const done=new Promise((resolve,reject)=>{
  child.on('error',reject);
  child.on('close',code=>code===0?resolve(output):reject(new Error('Docker fixture failed ('+code+'): '+output)));
 });
 return {done,child};
}
const setup=await launch('concurrency-setup.php',[run]).done;
const line=setup.split(/\r?\n/).find(line=>line.startsWith('RACE_FIXTURE '));
if(!line)throw new Error('Missing fixture: '+setup);
const fixture=JSON.parse(line.slice('RACE_FIXTURE '.length));
async function race(label,rows) {
 let release;
 const held=new Promise(resolve=>{release=resolve;});
 const first=launch('concurrency-worker.php',[run,String(rows[0]),'hold'],text=>{if(text.includes('LOCK_HELD'))release();});
 let timer;
 try {
  await Promise.race([held,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('Lock barrier timed out')),30000);}),first.done.then(()=>{throw new Error('Holder exited before lock barrier');})]);
  clearTimeout(timer);
  const second=launch('concurrency-worker.php',[run,String(rows[1]??rows[0]),'normal']);
  const outputs=await Promise.all([first.done,second.done]);
  if(!outputs[1].includes('WORKER_LOCK_BUSY'))throw new Error(label+': second worker did not observe database contention: '+outputs[1]);
  console.log('PASS '+label+': concurrent processes observed database lock contention');
 } finally {clearTimeout(timer);}
}
await race('same inbox row',fixture.same.rows);
await race('different tokens, same order',fixture.multi.rows);
const checked=await launch('concurrency-check.php',[run]).done;
console.log(checked.trim());
