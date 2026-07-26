<?php

declare(strict_types=1);

namespace Switon\Rendering\Tests\Fixtures;

use Switon\Core\Filesystem;
use Switon\Core\PathAliasInterface;

class FilesystemProbe extends Filesystem
{
    public function setPathAliasForTest(PathAliasInterface $pathAlias): static
    {
        $this->pathAlias = $pathAlias;

        return $this;
    }
}
