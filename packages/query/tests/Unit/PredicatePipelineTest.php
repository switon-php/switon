<?php

declare(strict_types=1);

namespace Switon\Query\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Query\Exception\InvalidFilterException;
use Switon\Query\PredicatePipeline;

class PredicatePipelineTest extends TestCase
{
    public function testParseSupportsRawExpressionForNumericKeys(): void
    {
        $parsed = (new PredicatePipeline())->parse([
            0 => '[id] > 1',
        ]);

        $this->assertSame(
            [['type' => 'expr', 'expr' => '[id] > 1', 'bind' => []]],
            $parsed
        );
    }

    public function testParseSkipsOptionalFilterWhenValueIsEmpty(): void
    {
        $parsed = (new PredicatePipeline())->parse([
            'name?=' => '   ',
            'email?=' => null,
        ]);

        $this->assertSame([], $parsed);
    }

    public function testParseRangeFilterRequiresExactlyTwoValues(): void
    {
        $this->expectException(InvalidFilterException::class);

        (new PredicatePipeline())->parse([
            'age~=' => [18],
        ]);
    }

    public function testParseArrayFilterWithUnsupportedOperatorSuffixRaisesInvalidFilterException(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessage('Unknown filter operator');

        // Suffix must be a multi-char operator not in (~=, !=, =, %=). Keys like "flags^=" only capture
        // trailing "=" because "^" is outside the parser's operator class, so they would be accepted as "in".
        (new PredicatePipeline())->parse([
            'flags@=' => [1, 2],
        ]);
    }

    public function testParseAssociativeArrayValueWithoutOperatorSuffixRaisesInvalidFilterException(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessage('indexed array or have operator suffix');

        (new PredicatePipeline())->parse([
            'roles' => ['admin' => true, 'editor' => false],
        ]);
    }

    public function testCompileFallsBackToLegacyCompilerWhenMappedMethodIsMissing(): void
    {
        $pipeline = new class () extends PredicatePipeline {
            public function setCompilers(array $compilers): void
            {
                $this->compilers = $compilers;
            }
        };
        $pipeline->setCompilers(['eq' => 'missingCompiler']);

        $compiled = $pipeline->compile([
            ['type' => 'eq', 'field' => 'user.id', 'value' => 5],
        ]);

        $this->assertSame(['[user].[id]=:user_id'], $compiled['conditions']);
        $this->assertSame(['user_id' => 5], $compiled['bind']);
    }

    public function testParseMultiFieldFilterRequiresOperatorSuffix(): void
    {
        $this->expectException(InvalidFilterException::class);
        $this->expectExceptionMessage('requires operator suffix');

        (new PredicatePipeline())->parse([
            'name,email' => 'x',
        ]);
    }

    public function testCompileStartsWithEndsWithContainsAndNullPredicates(): void
    {
        $pipeline = new PredicatePipeline();

        $compiled = $pipeline->compile([
            ['type' => 'starts_with', 'fields' => ['user.first'], 'value' => 'Al', 'not' => false, 'length' => null],
            ['type' => 'ends_with', 'fields' => ['user.last'], 'value' => 'son', 'not' => false],
            ['type' => 'contains', 'fields' => ['user.email'], 'value' => '@', 'not' => false, 'length' => null],
            ['type' => 'null', 'field' => 'deleted_at'],
        ]);

        $this->assertSame(
            [
                '[user].[first] LIKE :user_first',
                '[user].[last] LIKE :user_last',
                '[user].[email] LIKE :user_email',
                '[deleted_at] IS NULL',
            ],
            $compiled['conditions']
        );

        $this->assertSame('Al%', $compiled['bind']['user_first']);
        $this->assertSame('%son', $compiled['bind']['user_last']);
        $this->assertSame('%@%', $compiled['bind']['user_email']);
    }

    public function testCompileMultiFieldContainsBuildsOrConditionAndDistinctBindKeys(): void
    {
        $pipeline = new PredicatePipeline();

        $compiled = $pipeline->compile([
            ['type' => 'contains', 'fields' => ['a.b', 'a.b'], 'value' => 'x', 'not' => false, 'length' => null],
        ]);

        $this->assertSame(['[a].[b] LIKE :a_b OR [a].[b] LIKE :a_b_1'], $compiled['conditions']);
        $this->assertSame('%x%', $compiled['bind']['a_b']);
        $this->assertSame('%x%', $compiled['bind']['a_b_1']);
    }

    public function testCompileContainsWithNotUsesNotLikeAndAndForMultipleFields(): void
    {
        $pipeline = new PredicatePipeline();

        $compiled = $pipeline->compile([
            ['type' => 'contains', 'fields' => ['a', 'b'], 'value' => 'x', 'not' => true, 'length' => null],
        ]);

        $this->assertSame(['[a] NOT LIKE :a AND [b] NOT LIKE :b'], $compiled['conditions']);
        $this->assertSame('%x%', $compiled['bind']['a']);
        $this->assertSame('%x%', $compiled['bind']['b']);
    }

    public function testCompileBetweenAndInAndRegexPredicatesCoverEdgeBranches(): void
    {
        $pipeline = new PredicatePipeline();

        $compiled = $pipeline->compile([
            ['type' => 'between', 'field' => 'age', 'min' => null, 'max' => 10],
            ['type' => 'between', 'field' => 'score', 'min' => 3, 'max' => ''],
            ['type' => 'in', 'field' => 'id', 'values' => []],
            ['type' => 'in', 'field' => 'name', 'values' => ['a', 'b']],
            ['type' => 'regex', 'field' => 'title', 'regex' => '^[a-z]+$', 'flags' => ''],
            ['type' => 'not_regex', 'field' => 'body', 'regex' => 'x', 'flags' => 'i'],
        ]);

        $this->assertSame(
            [
                '[age]<=:age',
                '[score]>=:score',
                'FALSE',
                '[name] IN (:name_in_0, :name_in_1)',
                '[title] REGEXP BINARY :title',
                '[body] NOT REGEXP :body',
            ],
            $compiled['conditions']
        );

        $this->assertSame(10, $compiled['bind']['age']);
        $this->assertSame(3, $compiled['bind']['score']);
        $this->assertSame('a', $compiled['bind']['name_in_0']);
        $this->assertSame('^[a-z]+$', $compiled['bind']['title']);
        $this->assertSame('x', $compiled['bind']['body']);
    }

    public function testParseSupportsCommaAndOptionalOperatorEdgeCases(): void
    {
        $parsed = (new PredicatePipeline())->parse([
            'status,=' => 'active',
            'status,,=' => 'disabled',
            'name=?' => ' Alice ',
            'score>=?' => 10,
        ]);

        $this->assertSame(
            [
                ['type' => 'cmp', 'field' => 'status', 'operator' => '=', 'value' => 'active'],
                ['type' => 'cmp', 'field' => 'status', 'operator' => '=', 'value' => 'disabled'],
                ['type' => 'eq', 'field' => 'name', 'value' => 'Alice'],
                ['type' => 'cmp', 'field' => 'score', 'operator' => '>=', 'value' => 10],
            ],
            $parsed
        );
    }

    public function testCompileIgnoresPredicatesWithoutUsableType(): void
    {
        $pipeline = new PredicatePipeline();

        $compiled = $pipeline->compile([
            [],
            ['type' => null],
            ['type' => 123],
            ['type' => ''],
            ['type' => 'unknown'],
        ]);

        $this->assertSame(['conditions' => [], 'bind' => []], $compiled);
    }

    public function testCompileSupportsLegacyPredicateTypesAndFallbacks(): void
    {
        $pipeline = new PredicatePipeline();

        $compiled = $pipeline->compile([
            ['type' => 'mod', 'field' => 'n', 'divisor' => 3, 'remainder' => 1],
            ['type' => 'expr', 'expr' => '[x] = 1', 'bind' => ['x' => 1]],
            ['type' => 'not_between', 'field' => 'age', 'min' => 1, 'max' => 2],
            ['type' => 'not_in', 'field' => 'role', 'values' => ['admin', 'editor']],
            ['type' => 'inset', 'field' => 'tags', 'value' => 'vip', 'not' => false],
            ['type' => 'inset', 'field' => 'tags', 'value' => 'vip', 'not' => true],
            ['type' => 'not_null', 'field' => 'deleted_at'],
            ['type' => 'raw', 'filter' => '[score] > 10', 'bind' => ['score' => 10]],
        ]);

        $this->assertSame(
            [
                '[n]%3=1',
                '[x] = 1',
                '[age] NOT BETWEEN :age_min AND :age_max',
                '[role] NOT IN (:role_not_in_0, :role_not_in_1)',
                'FIND_IN_SET(:tags, [tags])>0',
                'FIND_IN_SET(:tags, [tags])=0',
                '[deleted_at] IS NOT NULL',
                '[score] > 10',
            ],
            $compiled['conditions']
        );

        $this->assertSame(
            [
                'x' => 1,
                'age_min' => 1,
                'age_max' => 2,
                'role_not_in_0' => 'admin',
                'role_not_in_1' => 'editor',
                'tags' => 'vip',
                'score' => 10,
            ],
            $compiled['bind']
        );
    }
}
