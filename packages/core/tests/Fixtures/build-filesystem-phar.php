<?php

declare(strict_types=1);

/**
 * Writes tests/Fixtures/filesystem.phar.
 *
 * The manifest timestamp is the constant 2006-03-01 12:00:00 UTC.
 * Phar::addFile() stores time() instead, so this script packs the archive itself.
 *
 * Run: php packages/core/tests/Fixtures/build-filesystem-phar.php
 */
$time = 1141214400;
$files = [
    'index.html' => "<h1>hi</h1>\n",
    'app.js' => "console.log(\"hi\");\n",
    'notes.txt' => "alpha\n\n  beta  \n",
    'events.jsonl' => "{\"id\":1}\n{\"id\":2}\n",
    '.hidden' => "secret\n",
    'Controller/HomeController.php' => "<?php // home\n",
    'Controller/Skip.txt' => "skip\n",
    'Areas/Admin/Controller/UserController.php' => "<?php // user\n",
    'Areas/Admin/Controller/RoleController.php' => "<?php // role\n",
    'Areas/Blog/Controller/PostController.php' => "<?php // post\n",
    'Areas/Blog/View/index.phtml' => "blog\n",
    'View/home.phtml' => "home\n",
    'View/layout/main.phtml' => "layout\n",
    'nested/a/stats.json' => "{}\n",
    'nested/b/stats.json' => "{}\n",
    'nested/b/other.txt' => "x\n",
];

$stub = "<?php __HALT_COMPILER(); ?>\n";
$manifest = '';
foreach ($files as $name => $contents) {
    $size = strlen($contents);
    $manifest .= pack('V', strlen($name)) . $name;
    $manifest .= pack('VVVVVV', $size, $time, $size, crc32($contents), 0644, 0);
}

$header = pack('VnVV', count($files), 0x1100, 0x00010000, 0) . pack('V', 0) . $manifest;
$body = $stub . pack('V', strlen($header)) . $header;
foreach ($files as $contents) {
    $body .= $contents;
}
$body .= hash('sha256', $body, true) . pack('V', 0x00000003) . 'GBMB';

$path = __DIR__ . '/filesystem.phar';
file_put_contents($path, $body);
fwrite(STDOUT, $path . ' ' . strlen($body) . " bytes\n");
