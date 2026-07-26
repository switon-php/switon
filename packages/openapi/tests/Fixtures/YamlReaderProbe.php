<?php

declare(strict_types=1);

namespace Switon\OpenApi\Tests\Fixtures;

use Switon\Core\FilesystemInterface;
use Switon\Yaml\YamlReader;

class YamlReaderProbe extends YamlReader
{
    public function assignFilesystemForTest(FilesystemInterface $filesystem): static
    {
        $this->filesystem = $filesystem;

        return $this;
    }
}
