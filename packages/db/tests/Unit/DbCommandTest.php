<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Switon\Core\ClockInterface;
use Switon\Core\ConsoleInterface;
use Switon\Core\FilesystemInterface;
use Switon\Db\ClientInterface;
use Switon\Db\Command\DbCommand;
use Switon\Di\NamedLookupInterface;
use RuntimeException;

use function json_decode;
use function count;
use function is_string;

#[AllowMockObjectsWithoutExpectations]
class DbCommandTest extends TestCase
{
    protected DbCommand $command;
    protected ConsoleInterface&MockObject $console;
    protected NamedLookupInterface&MockObject $namedLookup;
    protected FilesystemInterface&MockObject $filesystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new DbCommandProbe();
        $this->console = $this->createMock(ConsoleInterface::class);
        $this->namedLookup = $this->createMock(NamedLookupInterface::class);
        $this->filesystem = $this->createMock(FilesystemInterface::class);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('microtime')->willReturn(1_000.0);
        $clock->method('elapsed')->willReturn(0.001);

        $this->command->setClock($clock);
        $this->command->setConsole($this->console);
        $this->command->setNamedLookup($this->namedLookup);
        $this->command->setFilesystem($this->filesystem);
    }

    public function testPingActionReturnsOkWhenSelectOneReturnsRow(): void
    {
        $db = $this->createMock(ClientInterface::class);
        $db->expects($this->once())
            ->method('fetchAll')
            ->with('SELECT 1')
            ->willReturn([[1]]);
        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willReturn($db);
        $this->console->expects($this->once())->method('writeLn')->with('OK');

        $code = $this->command->pingAction();

        $this->assertSame(0, $code);
    }

    public function testPingActionReturnsConfiguredErrorWhenConnectionMissing(): void
    {
        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'primary')
            ->willThrowException(new RuntimeException('service not found'));

        $this->console->expects($this->once())
            ->method('error')
            ->with('Connection "primary" not configured', [], 1)
            ->willReturn(1);

        $code = $this->command->pingAction('primary');

        $this->assertSame(1, $code);
    }

    public function testInfoActionReturnsJsonWithRedactedConnectionUri(): void
    {
        $db = $this->createMock(ClientInterface::class);
        $db->method('fetchAll')->with('SELECT 1')->willReturn([[1]]);
        $db->method('getUri')->willReturn('mysql://user:pass@127.0.0.1:3306/app');
        $db->method('getVersion')->willReturn('8.0');
        $this->namedLookup->method('by')->willReturn($db);

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->infoAction('default', false, true);

        $this->assertSame(0, $code);
        $this->assertSame('default', $captured['connection']);
        $this->assertSame('Connected', $captured['status']);
        $this->assertSame('mysql://127.0.0.1:3306/app', $captured['uri']);
        $this->assertSame('8.0', $captured['version']);
        $this->assertArrayHasKey('latency_ms', $captured);
    }

    public function testInfoActionReturnsJsonErrorOnFailure(): void
    {
        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'default')
            ->willThrowException(new RuntimeException('db offline'));

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->infoAction();

        $this->assertSame(1, $code);
        $this->assertSame('db offline', $captured['error']);
        $this->assertSame('default', $captured['connection']);
    }

    public function testListActionReturnsTablesAndConnectionErrorsInJson(): void
    {
        $okDb = $this->createMock(ClientInterface::class);
        $okDb->method('getTables')->willReturn(['users', 'orders']);

        $this->namedLookup->expects($this->exactly(2))
            ->method('by')
            ->willReturnCallback(static function (string $type, string $name) use ($okDb): ClientInterface {
                if ($name === 'db1') {
                    return $okDb;
                }
                throw new RuntimeException('connection failed');
            });

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true);
            });

        $code = $this->command->listAction(['db1', 'db2'], '', true);

        $this->assertSame(1, $code);
        $this->assertSame(
            [
                ['connection' => 'db1', 'table' => 'orders'],
                ['connection' => 'db1', 'table' => 'users'],
            ],
            $captured['tables']
        );
        $this->assertSame(['db2' => 'connection failed'], $captured['errors']);
    }

    public function testPingActionFailsWhenSelectOneReturnsNoRows(): void
    {
        $db = $this->createMock(ClientInterface::class);
        $db->method('fetchAll')->willReturn([]);
        $this->namedLookup->method('by')->willReturn($db);
        $this->console->expects($this->once())
            ->method('error')
            ->with('Ping failed: no response', [], 1)
            ->willReturn(1);

        $this->assertSame(1, $this->command->pingAction());
    }

    public function testPingActionUsesFriendlyMessageWhenServiceNotRegistered(): void
    {
        $this->namedLookup->method('by')->willThrowException(
            new RuntimeException('ClientInterface not registered in container')
        );
        $this->console->expects($this->once())
            ->method('error')
            ->with('Connection "default" not configured', [], 1)
            ->willReturn(1);

        $this->assertSame(1, $this->command->pingAction());
    }

    public function testPingActionPassesThroughDriverErrorMessage(): void
    {
        $this->namedLookup->method('by')->willThrowException(
            new RuntimeException('SQLSTATE[HY000] server has gone away')
        );
        $this->console->expects($this->once())
            ->method('error')
            ->with('Ping failed: {message}', ['message' => 'SQLSTATE[HY000] server has gone away'], 1)
            ->willReturn(1);

        $this->assertSame(1, $this->command->pingAction(''));
    }

    public function testInfoActionFailsWhenSelectOneReturnsNoRows(): void
    {
        $db = $this->createMock(ClientInterface::class);
        $db->method('fetchAll')->willReturn([]);
        $this->namedLookup->method('by')->willReturn($db);
        $this->console->expects($this->once())
            ->method('error')
            ->with('Connection check failed: SELECT 1 returned no row', [], 1)
            ->willReturn(1);

        $this->assertSame(1, $this->command->infoAction());
    }

    public function testInfoActionIncludesFullUriWhenUriFlagTrue(): void
    {
        $db = $this->createMock(ClientInterface::class);
        $db->method('fetchAll')->willReturn([[1]]);
        $full = 'mysql://user:secret@10.0.0.1:3306/mydb';
        $db->method('getUri')->willReturn($full);
        $db->method('getVersion')->willReturn('8.0.30');
        $this->namedLookup->method('by')->willReturn($db);

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            });

        $this->assertSame(0, $this->command->infoAction('', true, true));
        $this->assertSame($full, $captured['uri']);
    }

    public function testInfoActionOmitsUriWhenClientReturnsNullUri(): void
    {
        $db = $this->createMock(ClientInterface::class);
        $db->method('fetchAll')->willReturn([[1]]);
        $db->method('getUri')->willReturn(null);
        $db->method('getVersion')->willReturn(null);
        $this->namedLookup->method('by')->willReturn($db);

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            });

        $this->assertSame(0, $this->command->infoAction());
        $this->assertSame('', $captured['uri']);
        $this->assertNull($captured['version']);
    }

    public function testInfoActionNonJsonRendersTableRow(): void
    {
        $db = $this->createMock(ClientInterface::class);
        $db->method('fetchAll')->willReturn([[1]]);
        $db->method('getUri')->willReturn('pgsql://u:p@db.example:5432/app');
        $db->method('getVersion')->willReturn('16');
        $this->namedLookup->method('by')->willReturn($db);

        $this->console->expects($this->once())->method('table')
            ->with(
                ['connection', 'status', 'latency', 'uri', 'version'],
                $this->callback(static function (array $rows): bool {
                    if (count($rows) !== 1) {
                        return false;
                    }
                    $row = $rows[0];
                    return $row[0] === 'default'
                        && $row[1] === 'Connected'
                        && is_string($row[2]) && str_ends_with($row[2], 'ms')
                        && $row[3] === 'pgsql://db.example:5432/app'
                        && $row[4] === '16';
                }),
                8
            );

        $this->assertSame(0, $this->command->infoAction('', false, false));
    }

    public function testInfoActionNonJsonErrorUsesConsoleError(): void
    {
        $this->namedLookup->method('by')->willThrowException(new RuntimeException('timeout'));
        $this->console->expects($this->once())
            ->method('error')
            ->with('Info failed: {message}', ['message' => 'timeout'], 1)
            ->willReturn(1);

        $this->assertSame(1, $this->command->infoAction('', false, false));
    }

    public function testListActionUsesRegisteredConnectionNamesWhenConnectionsEmpty(): void
    {
        $db = $this->createMock(ClientInterface::class);
        $db->expects($this->once())->method('getTables')->willReturn([]);
        $this->namedLookup->expects($this->once())
            ->method('names')
            ->with(ClientInterface::class)
            ->willReturn(['solo']);
        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'solo')
            ->willReturn($db);
        $this->console->expects($this->once())->method('writeLn')->with('No tables found.');

        $this->assertSame(0, $this->command->listAction([], '', false));
    }

    public function testListActionFiltersTablesWithFnmatchPattern(): void
    {
        $db = $this->createMock(ClientInterface::class);
        $db->method('getTables')->willReturn(['users', 'posts', 'user_meta']);
        $this->namedLookup->method('by')->willReturn($db);

        $captured = null;
        $this->console->expects($this->once())->method('writeLn')
            ->willReturnCallback(static function (string $line) use (&$captured): void {
                $captured = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            });

        $this->assertSame(0, $this->command->listAction(['main'], 'user*', true));
        $this->assertSame(
            [
                ['connection' => 'main', 'table' => 'user_meta'],
                ['connection' => 'main', 'table' => 'users'],
            ],
            $captured['tables']
        );
    }

    public function testListActionNonJsonReturnsErrorWhenFirstConnectionFails(): void
    {
        $this->namedLookup->expects($this->once())
            ->method('by')
            ->with(ClientInterface::class, 'bad')
            ->willThrowException(new RuntimeException('refused'));
        $this->console->expects($this->once())
            ->method('error')
            ->with('List failed for {connection}: {message}', [
                'connection' => 'bad',
                'message' => 'refused',
            ], 1)
            ->willReturn(1);

        $this->assertSame(1, $this->command->listAction(['bad'], '', false));
    }

    public function testListActionNonJsonPrintsTableWhenRowsExist(): void
    {
        $db = $this->createMock(ClientInterface::class);
        $db->method('getTables')->willReturn(['b', 'a']);
        $this->namedLookup->method('by')->willReturn($db);

        $this->console->expects($this->once())->method('table')
            ->with(
                ['connection', 'table'],
                [
                    ['app', 'a'],
                    ['app', 'b'],
                ],
                8
            );

        $this->assertSame(0, $this->command->listAction(['app'], '', false));
    }

    public function testMetadataActionWritesPrettyPrintedJson(): void
    {
        $db = $this->createMock(ClientInterface::class);
        $db->method('getTables')->willReturn(['items']);
        $db->method('getMetadata')->willReturn([
            ['id' => 'INTEGER'],
            ['id'],
            'id',
            [],
        ]);
        $this->namedLookup->method('names')->willReturn(['default']);
        $this->namedLookup->method('by')->willReturn($db);

        $this->console->expects($this->once())->method('write')
            ->with($this->callback(static function (string $json): bool {
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

                return str_contains($json, "\n")
                    && $data['default']['items']['primaryKey'] === ['id']
                    && $data['default']['items']['autoIncrement'] === 'id'
                    && $data['default']['items']['attributes'] === ['id' => 'INTEGER'];
            }));

        $this->command->metadataAction([], '', false);
    }

    public function testMetadataActionPlainUsesCompactJson(): void
    {
        $db = $this->createMock(ClientInterface::class);
        $db->method('getTables')->willReturn(['t']);
        $db->method('getMetadata')->willReturn([
            [],
            [],
            null,
            [],
        ]);
        $this->namedLookup->method('names')->willReturn(['default']);
        $this->namedLookup->method('by')->willReturn($db);

        $this->console->expects($this->once())->method('write')
            ->with($this->callback(static function (string $json): bool {
                if (str_contains($json, "\n")) {
                    return false;
                }
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

                return $data === ['default' => ['t' => [
                        'attributes' => [],
                        'primaryKey' => [],
                        'autoIncrement' => null,
                    ]]];
            }));

        $this->command->metadataAction([], '', true);
    }

}

class DbCommandProbe extends DbCommand
{
    public function setClock(ClockInterface $clock): void
    {
        $this->clock = $clock;
    }

    public function setConsole(ConsoleInterface $console): void
    {
        $this->console = $console;
    }

    public function setNamedLookup(NamedLookupInterface $namedLookup): void
    {
        $this->namedLookup = $namedLookup;
    }

    public function setFilesystem(FilesystemInterface $filesystem): void
    {
        $this->filesystem = $filesystem;
    }
}
