import { spawnSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
function run(args, {optional = false} = {}) {
  const result = spawnSync('docker', ['compose', ...args], {cwd: root, stdio: 'inherit', windowsHide: true, timeout: 240000});
  if (result.error) throw result.error;
  if (result.status !== 0 && !optional) throw new Error(`docker compose ${args[0]} failed (${result.status})`);
  return result.status;
}
run(['up', '-d', 'db', 'mock', 'wordpress']);
// Wait for WordPress files to be initialized by the official image entry point.
for (let attempt=0; attempt<30; attempt++) {
  try { const response = await fetch('http://localhost:18080', {signal: AbortSignal.timeout(3000)}); if (response.status < 500) break; } catch {}
  if (attempt === 29) throw new Error('WordPress did not become ready');
  await new Promise(resolve => setTimeout(resolve, 2000));
}
if (run(['run','--rm','cli','core','is-installed'], {optional:true}) !== 0) {
  run(['run','--rm','cli','core','install','--url=http://localhost:18080','--title=Paymento EDD Integration Fixture','--admin_user=paymento-test-admin','--admin_password=synthetic-local-admin-password','--admin_email=test@example.invalid','--skip-email']);
}
if (run(['run','--rm','cli','plugin','is-installed','easy-digital-downloads'], {optional:true}) !== 0) {
  run(['run','--rm','cli','plugin','install','easy-digital-downloads','--version=3.7.2','--activate']);
}
run(['run','--rm','cli','plugin','activate','easy-digital-downloads','paymento-easy-digital-downloads']);
for (const file of ['bootstrap.php','integration.php','attempt-refunds.php']) run(['run','--rm','cli','eval-file',`wp-content/plugins/paymento-easy-digital-downloads/tests/${file}`]);
for (const file of ['browser-checkout.mjs','concurrency.mjs','admin-security.mjs']) {
  const result = spawnSync(process.execPath, [resolve(root,'tests',file)], {cwd:root, stdio:'inherit', env:{...process.env,EDD_COMPOSE_DIR:root}, windowsHide:true, timeout:240000});
  if (result.error) throw result.error;
  if (result.status !== 0) throw new Error(`${file} failed (${result.status})`);
}
console.log('Real WordPress/EDD integration + guest HTTP checkout + DB concurrency + admin security passed. Paymento API was mocked.');
