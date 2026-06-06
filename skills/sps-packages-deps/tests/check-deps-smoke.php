#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/scripts/check-deps.php';
require dirname(__DIR__) . '/scripts/graph-deps.php';

function assertSameString(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected:\n{$expected}\nActual:\n{$actual}\n");
        exit(1);
    }
}

function writeFile(string $path, string $content): void
{
    $dir = dirname($path);

    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    file_put_contents($path, $content);
}

$root = sys_get_temp_dir() . '/switon-check-deps-' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
mkdir($root . '/packages', 0777, true);

writeFile($root . '/packages/cli/composer.json', <<<'JSON'
{
  "require": {
    "php": ">=8.3",
    "switon/core": "*"
  },
  "require-dev": {
    "switon/testing": "^1.0"
  },
  "autoload": {
    "psr-4": {
      "Switon\\Cli\\": "src/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "Switon\\Cli\\Tests\\": "tests/"
    },
    "files": [
      "helpers.php"
    ]
  }
}
JSON);

writeFile($root . '/packages/cli/src/Kernel.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Switon\Cli;

use Switon\Core\Attribute\Scene;

#[Scene('cli')]
class Kernel extends \Switon\Kernel\Kernel
{
}
PHP);

writeFile($root . '/packages/cli/src/DocOnly.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Switon\Cli;

/**
 * @see \Switon\Http\Request
 */
class DocOnly
{
    protected string $label = 'Switon\\Http\\Request string should not count';
}
PHP);

writeFile($root . '/packages/cli/tests/KernelTest.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Switon\Cli\Tests;

class KernelTest
{
    public function testMake(): void
    {
        new \Switon\Http\Request();
    }
}
PHP);

writeFile($root . '/packages/cli/helpers.php', <<<'PHP'
<?php

declare(strict_types=1);

use Switon\Context\ContextManager;
PHP);

$expected = <<<'OUT'
=== cli ===
  require MISSING: switon/context, switon/kernel
  require-dev MISSING: switon/http
OUT;

$actual = renderDependencyReport(buildDependencyReport($root));

assertSameString($expected, trim($actual), 'Dependency report did not match expected output.');

echo "OK\n";
