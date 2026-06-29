<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Db\ClientInterface;
use Switon\Query\Clauses;
use Switon\Query\SqlBuilder;

class SqlBuilderTest extends TestCase
{
    public function testBuildSqlBuildsParamsAndTranslatesColumnsAndPlaceholders(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->exactly(2))
            ->method('getPrefix')
            ->willReturn('pre_');

        $capturedParams = null;
        $client->expects($this->once())
            ->method('buildSql')
            ->willReturnCallback(static function (array $params) use (&$capturedParams): string {
                $capturedParams = $params;
                return 'SELECT [id], [name] FROM [x] WHERE [id] = :id: AND [name] = :name:';
            });

        $clauses = new Clauses();
        $clauses->distinct = true;
        $clauses->fields = ['id', 'name'];
        $clauses->alias = 'u';
        $clauses->joins = [
            ['profiles', '[u.id] = [p.user_id]', 'p', 'LEFT'],
        ];
        $clauses->conditions = ['[id]=:id: or [status]=1', '[name]=:name:'];
        $clauses->group = ['u.id'];
        $clauses->order = ['u.id' => SORT_DESC];
        $clauses->limit = 10;
        $clauses->offset = 20;
        $clauses->forUpdate = true;
        $clauses->columnMap = ['id' => 'user_id', 'name' => 'full_name'];
        $clauses->bind = ['id' => 7, 'name' => 'alice'];

        $sql = (new SqlBuilder())->buildSql($client, 'users.main', $clauses);

        $this->assertSame('[pre_users].[main] AS [u]', $capturedParams['from']);
        $this->assertSame(' LEFT JOIN [pre_profiles] AS [p] ON [u.id] = [p.user_id]', $capturedParams['join']);
        $this->assertSame('([id]=:id: or [status]=1) AND [name]=:name:', $capturedParams['where']);
        $this->assertSame('[u].[id]', $capturedParams['group']);
        $this->assertSame('[u].[id] DESC', $capturedParams['order']);
        $this->assertSame(10, $capturedParams['limit']);
        $this->assertSame(20, $capturedParams['offset']);
        $this->assertTrue($capturedParams['distinct']);
        $this->assertTrue($capturedParams['forUpdate']);
        $this->assertSame('SELECT [user_id], [full_name] FROM [x] WHERE [user_id] = :id AND [full_name] = :name', $sql);
    }

    public function testBuildSqlStringifiesInvalidGroupItemType(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('getPrefix')->willReturn('');

        $capturedParams = null;
        $client->expects($this->once())
            ->method('buildSql')
            ->willReturnCallback(static function (array $params) use (&$capturedParams): string {
                $capturedParams = $params;
                return 'SELECT 1';
            });

        $clauses = new Clauses();
        $clauses->group = [123];

        (new SqlBuilder())->buildSql($client, 'users', $clauses);

        $this->assertSame('[123]', $capturedParams['group']);
    }

    public function testBuildSqlStringifiesInvalidOrderFieldKeyType(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('getPrefix')->willReturn('');

        $capturedParams = null;
        $client->expects($this->once())
            ->method('buildSql')
            ->willReturnCallback(static function (array $params) use (&$capturedParams): string {
                $capturedParams = $params;
                return 'SELECT 1';
            });

        $clauses = new Clauses();
        $clauses->order = [0 => SORT_ASC];

        (new SqlBuilder())->buildSql($client, 'users', $clauses);

        $this->assertSame('[0] ASC', $capturedParams['order']);
    }
}
