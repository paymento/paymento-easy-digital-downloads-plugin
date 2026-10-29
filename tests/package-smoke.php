<?php
if (wp_get_environment_type() !== 'local') { throw new RuntimeException('Fixture only.'); }
if (class_exists('Paymento_EDD')) { throw new RuntimeException('Skip source plugin before package test.'); }
$zip = new ZipArchive();
if ($zip->open('/tmp/paymento-edd-package.zip') !== true) { throw new RuntimeException('ZIP open failed.'); }
try {
    for ($index=0; $index<$zip->numFiles; $index++) {
        $name=$zip->getNameIndex($index);
        if (str_contains($name, '\\') || str_contains($name, '..') || !str_starts_with($name,'paymento-easy-digital-downloads/')) { throw new RuntimeException('Unsafe/nonportable ZIP entry.'); }
    }
    if (!$zip->extractTo('/tmp/paymento-package-smoke')) { throw new RuntimeException('Extraction failed.'); }
} finally { $zip->close(); }
require '/tmp/paymento-package-smoke/paymento-easy-digital-downloads/paymento-easy-digital-downloads.php';
Paymento_EDD::activate();
echo 'PACKAGED_GATEWAY_LOADED' . PHP_EOL;
require __DIR__ . '/integration.php';
