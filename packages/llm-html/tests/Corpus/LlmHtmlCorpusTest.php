<?php

declare(strict_types=1);

namespace Switon\LlmHtml\Tests\Corpus;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Switon\LlmHtml\LlmHtml;
use Switon\LlmHtml\LlmHtmlInterface;
use Switon\LlmHtml\LlmHtmlResult;
use Switon\LlmHtml\Tests\TestCase;

/**
 * Wide behavioral corpus: each case is a goal (HTML → expectations), not an upstream algorithm port.
 */
#[CoversClass(LlmHtml::class)]
class LlmHtmlCorpusTest extends TestCase
{
    protected function converter(): LlmHtmlInterface
    {
        return $this->make(LlmHtmlInterface::class);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function corpusProvider(): array
    {
        $out = [];
        foreach (CorpusCatalog::cases() as $case) {
            $out[$case['id']] = [$case];
        }

        return $out;
    }

    public function testCorpusHasExpectedScale(): void
    {
        $count = count(CorpusCatalog::cases());
        $this->assertGreaterThanOrEqual(
            750,
            $count,
            'Corpus should stay large enough for broad LLM HTML→MD goal coverage; got ' . $count,
        );
    }

    /**
     * @param array{
     *   id: string,
     *   html: string,
     *   contains?: list<string>,
     *   notContains?: list<string>,
     *   title?: string,
     *   description?: string,
     *   strategy?: string,
     *   usedMain?: bool
     * } $case
     */
    #[DataProvider('corpusProvider')]
    public function testCorpusCase(array $case): void
    {
        $result = $this->converter()->convert($case['html']);
        $this->assertInstanceOf(LlmHtmlResult::class, $result);

        foreach ($case['contains'] ?? [] as $needle) {
            $this->assertStringContainsString($needle, $result->content, $case['id'] . ' contains');
        }

        foreach ($case['notContains'] ?? [] as $needle) {
            $this->assertStringNotContainsString($needle, $result->content, $case['id'] . ' notContains');
        }

        if (array_key_exists('title', $case)) {
            $this->assertSame($case['title'], $result->title, $case['id'] . ' title');
        }

        if (array_key_exists('description', $case)) {
            $this->assertSame($case['description'], $result->description, $case['id'] . ' description');
        }

        if (array_key_exists('strategy', $case)) {
            $this->assertSame($case['strategy'], $result->strategy, $case['id'] . ' strategy');
        }

        if (array_key_exists('usedMain', $case)) {
            $this->assertSame($case['usedMain'], $result->usedMainContent, $case['id'] . ' usedMain');
        }
    }
}
