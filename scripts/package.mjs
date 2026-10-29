import { mkdirSync, cpSync, rmSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const artifacts = join(root,'artifacts');
const stage = join(artifacts,'package','paymento-easy-digital-downloads');
// The only recursive removal target is the fixed package stage inside this repo's ignored artifacts directory.
if (!stage.startsWith(root + (process.platform === 'win32' ? '\\' : '/'))) throw new Error('Invalid staging path');
rmSync(stage,{recursive:true,force:true});
mkdirSync(stage,{recursive:true});
for (const item of ['paymento-easy-digital-downloads.php','includes','readme.txt','LICENSE']) cpSync(join(root,item),join(stage,item),{recursive:true});
const {version} = JSON.parse(readFileSync(join(root,'package.json'),'utf8'));
const zipPath = join(artifacts,`paymento-easy-digital-downloads-${version}.zip`);
rmSync(zipPath,{force:true});
let result;
if (process.platform === 'win32') {
  const script = `
    $ErrorActionPreference = 'Stop'
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $packageRoot = $env:PAYMENTO_PACKAGE_STAGE
    $zip = [IO.Compression.ZipFile]::Open($env:PAYMENTO_PACKAGE_ZIP, [IO.Compression.ZipArchiveMode]::Create)
    try {
      foreach ($file in (Get-ChildItem -LiteralPath $packageRoot -File -Recurse | Sort-Object FullName)) {
        $relative = $file.FullName.Substring($packageRoot.Length).TrimStart([char]92).Replace([char]92, [char]47)
        $entry = $zip.CreateEntry('paymento-easy-digital-downloads/' + $relative, [IO.Compression.CompressionLevel]::Optimal)
        $entry.LastWriteTime = [DateTimeOffset]::Parse('2026-01-01T00:00:00Z')
        $inputStream = [IO.File]::OpenRead($file.FullName)
        $outputStream = $entry.Open()
        try { $inputStream.CopyTo($outputStream) } finally { $outputStream.Dispose(); $inputStream.Dispose() }
      }
    } finally { $zip.Dispose() }
  `;
  result=spawnSync('powershell',['-NoProfile','-Command',script],{env:{...process.env,PAYMENTO_PACKAGE_STAGE:stage,PAYMENTO_PACKAGE_ZIP:zipPath},encoding:'utf8',windowsHide:true});
} else {
  result=spawnSync('zip',['-r',zipPath,'paymento-easy-digital-downloads'],{cwd:dirname(stage),encoding:'utf8'});
}
if (result.error || result.status !== 0) throw new Error(result.error?.message || result.stderr);
const digest=createHash('sha256').update(readFileSync(zipPath)).digest('hex');
writeFileSync(zipPath+'.sha256',`${digest}  ${zipPath.split(/[\\/]/).at(-1)}\n`);
console.log(`${zipPath}\nSHA256 ${digest}`);
