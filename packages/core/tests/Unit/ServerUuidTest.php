<?php

declare(strict_types=1);

namespace Switon\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\App;
use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Core\ServerUuid;
use Switon\Core\ServerUuidInterface;
use Switon\Core\Tests\TestCase;

#[CoversClass(ServerUuid::class)]
class ServerUuidTest extends TestCase
{
    #[Autowired] protected ServerUuidInterface $serverUuid;

    protected string $runtimeDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runtimeDir = sys_get_temp_dir() . '/switon_server_uuid_' . bin2hex(random_bytes(4));
        @mkdir($this->runtimeDir, 0755, true);

        $pathAlias = $this->container->get(PathAliasInterface::class);
        $pathAlias->set('@runtime', $this->runtimeDir);
    }

    protected function tearDown(): void
    {
        $path = $this->runtimeDir . '/server-uuid';
        if (is_file($path)) {
            unlink($path);
        }
        if (is_dir($this->runtimeDir)) {
            rmdir($this->runtimeDir);
        }

        parent::tearDown();
    }

    public function testImplementsServerUuidInterface(): void
    {
        $this->assertInstanceOf(ServerUuidInterface::class, $this->serverUuid);
    }

    public function testGetReusesUuidFromExistingFile(): void
    {
        $stored = 'a1b2c3d4-e5f6-4789-a012-3456789abcde';
        file_put_contents($this->runtimeDir . '/server-uuid', $stored . "\n");

        $this->assertSame($stored, $this->serverUuid->get());
        $this->assertSame($stored, $this->serverUuid->get());
    }

    public function testGetMintsAndReusesUuid(): void
    {
        $filesystem = $this->container->get(FilesystemInterface::class);
        $path = $this->runtimeDir . '/server-uuid';

        $this->assertFalse($filesystem->exists($path));

        $first = $this->serverUuid->get();
        $second = $this->serverUuid->get();

        $this->assertSame($first, $second);
        $this->assertTrue($filesystem->exists($path));
        $this->assertSame($first, trim($filesystem->read($path)));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $first,
        );
    }

    public function testGetCachesForProcessLifetime(): void
    {
        $first = $this->serverUuid->get();
        unlink($this->runtimeDir . '/server-uuid');

        $this->assertSame($first, $this->serverUuid->get());
    }

    public function testAppGetUsesSameInstance(): void
    {
        $this->assertSame($this->serverUuid->get(), App::get(ServerUuidInterface::class)->get());
    }

    public function testGetRemintsWhenStoredValueIsBlank(): void
    {
        $path = $this->runtimeDir . '/server-uuid';
        file_put_contents($path, "  \n");

        $uuid = $this->make(ServerUuid::class);
        $id = $uuid->get();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $id,
        );
        $this->assertSame($id, trim(file_get_contents($path)));
    }
}
