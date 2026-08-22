<?php

declare(strict_types=1);

namespace Switon\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Switon\Search\CssToXPath;

/**
 * Selector mapping from ManaPHP tests/DomCssToXPathTest.php.
 */
class CssToXPathTest extends TestCase
{
    #[DataProvider('selectors')]
    public function testTransform(string $css, string $xpath): void
    {
        $this->assertSame($xpath, (new CssToXPath())->transform($css));
    }

    public function testRelativeQueryStartsAtTheCurrentNode(): void
    {
        $css = new CssToXPath();

        $this->assertSame(
            ".//*[contains(concat(' ', normalize-space(@class), ' '), ' error ')]",
            $css->transform('.error', true),
        );
        $this->assertSame('.//h3//a', $css->transform('h3 a', true));
        $this->assertSame('.//div/span', $css->transform('div > span', true));
        $this->assertSame('.//div|.//span', $css->transform('div, span', true));
    }

    /** @return array<string, array{string, string}> */
    public static function selectors(): array
    {
        $cases = [
            '*' => '//*',
            'div' => '//div',
            'a' => '//a',
            'span' => '//span',
            'h2' => '//h2',

            '.error' => "//*[contains(concat(' ', normalize-space(@class), ' '), ' error ')]",
            'div.error' => "//div[contains(concat(' ', normalize-space(@class), ' '), ' error ')]",
            'label.required' => "//label[contains(concat(' ', normalize-space(@class), ' '), ' required ')]",
            'label.error.required' => "//label[contains(concat(' ', normalize-space(@class), ' '), ' error ')][contains(concat(' ', normalize-space(@class), ' '), ' required ')]",

            '#content' => "//*[@id='content']",
            'div#nav' => "//div[@id='nav']",

            'div[bar]' => '//div[@bar]',
            'div[bar|xyz]' => '//div[@bar or @xyz]',
            'div[bar&xyz]' => '//div[@bar and @xyz]',

            'div[bar=baz]' => "//div[@bar='baz']",
            'div[bar="baz"]' => "//div[@bar='baz']",
            "div[bar='baz']" => "//div[@bar='baz']",
            'div[bar="baz|xyz"]' => "//div[@bar='baz' or @bar='xyz']",
            'div[bar="baz&xyz"]' => "//div[@bar='baz' and @bar='xyz']",

            'div[bar~=baz]' => "//div[contains(concat(' ', normalize-space(@bar), ' '), ' baz ')]",
            'div[bar~="baz"]' => "//div[contains(concat(' ', normalize-space(@bar), ' '), ' baz ')]",
            'div[bar~="baz|xyz"]' => "//div[contains(concat(' ', normalize-space(@bar), ' '), ' baz ') or contains(concat(' ', normalize-space(@bar), ' '), ' xyz ')]",
            'div[bar~="baz&xyz"]' => "//div[contains(concat(' ', normalize-space(@bar), ' '), ' baz ') and contains(concat(' ', normalize-space(@bar), ' '), ' xyz ')]",

            'div[bar|=baz]' => "//div[@bar='baz' or starts-with(@bar,'baz-')]",
            'div[bar|="baz"]' => "//div[@bar='baz' or starts-with(@bar,'baz-')]",
            'div[bar|="baz|xyz"]' => "//div[(@bar='baz' or starts-with(@bar,'baz-')) or (@bar='xyz' or starts-with(@bar,'xyz-'))]",
            'div[bar|="baz&xyz"]' => "//div[(@bar='baz' or starts-with(@bar,'baz-')) and (@bar='xyz' or starts-with(@bar,'xyz-'))]",

            'div[bar!=baz]' => "//div[not(@bar) or @bar!='baz']",
            'div[bar!="baz"]' => "//div[not(@bar) or @bar!='baz']",
            'div[bar!="baz|xyz"]' => "//div[(not(@bar) or @bar!='baz') or (not(@bar) or @bar!='xyz')]",
            'div[bar!="baz&xyz"]' => "//div[(not(@bar) or @bar!='baz') and (not(@bar) or @bar!='xyz')]",

            'div[bar^=baz]' => "//div[starts-with(@bar, 'baz')]",
            'div[bar^="baz"]' => "//div[starts-with(@bar, 'baz')]",
            'div[bar^="baz|xyz"]' => "//div[starts-with(@bar, 'baz') or starts-with(@bar, 'xyz')]",
            'div[bar^="baz&xyz"]' => "//div[starts-with(@bar, 'baz') and starts-with(@bar, 'xyz')]",

            'div[bar$=baz]' => "//div[ends-with(@bar, 'baz')]",
            'div[bar$="baz"]' => "//div[ends-with(@bar, 'baz')]",
            'div[bar$="baz|xyz"]' => "//div[ends-with(@bar, 'baz') or ends-with(@bar, 'xyz')]",
            'div[bar$="baz&xyz"]' => "//div[ends-with(@bar, 'baz') and ends-with(@bar, 'xyz')]",

            'div[bar*=baz]' => "//div[contains(@bar, 'baz')]",
            'div[bar*="baz"]' => "//div[contains(@bar, 'baz')]",
            'div[bar*="baz|xyz"]' => "//div[contains(@bar, 'baz') or contains(@bar, 'xyz')]",
            'div[bar*="baz&xyz"]' => "//div[contains(@bar, 'baz') and contains(@bar, 'xyz')]",

            'div[=baz]' => "//div[text()='baz']",
            'div[="baz"]' => "//div[text()='baz']",
            'div[="baz|xyz"]' => "//div[text()='baz' or text()='xyz']",
            'div[="baz&xyz"]' => "//div[text()='baz' and text()='xyz']",

            'div[~=baz]' => "//div[contains(concat(' ', normalize-space(text()), ' '), ' baz ')]",
            'div[~="baz"]' => "//div[contains(concat(' ', normalize-space(text()), ' '), ' baz ')]",
            'div[~="baz|xyz"]' => "//div[contains(concat(' ', normalize-space(text()), ' '), ' baz ') or contains(concat(' ', normalize-space(text()), ' '), ' xyz ')]",
            'div[~="baz&xyz"]' => "//div[contains(concat(' ', normalize-space(text()), ' '), ' baz ') and contains(concat(' ', normalize-space(text()), ' '), ' xyz ')]",

            'div[^=baz]' => "//div[starts-with(text(), 'baz')]",
            'div[^="baz"]' => "//div[starts-with(text(), 'baz')]",
            'div[^="baz|xyz"]' => "//div[starts-with(text(), 'baz') or starts-with(text(), 'xyz')]",
            'div[^="baz&xyz"]' => "//div[starts-with(text(), 'baz') and starts-with(text(), 'xyz')]",

            'div[$=baz]' => "//div[ends-with(text(), 'baz')]",
            'div[$="baz"]' => "//div[ends-with(text(), 'baz')]",
            'div[$="baz|xyz"]' => "//div[ends-with(text(), 'baz') or ends-with(text(), 'xyz')]",
            'div[$="baz&xyz"]' => "//div[ends-with(text(), 'baz') and ends-with(text(), 'xyz')]",

            'div[*=baz]' => "//div[contains(text(), 'baz')]",
            'div[*="baz"]' => "//div[contains(text(), 'baz')]",
            'div[*="baz|xyz"]' => "//div[contains(text(), 'baz') or contains(text(), 'xyz')]",
            'div[*="baz&xyz"]' => "//div[contains(text(), 'baz') and contains(text(), 'xyz')]",

            'div > span' => '//div/span',
            'div .foo span #one' => "//div//*[contains(concat(' ', normalize-space(@class), ' '), ' foo ')]//span//*[@id='one']|//div[contains(concat(' ', normalize-space(@class), ' '), ' foo ')]//span//*[@id='one']",

            'foo|bar' => '//foo|bar',

            'div>.foo' => "//div/[contains(concat(' ', normalize-space(@class), ' '), ' foo ')]",
            'div > .foo' => "//div/[contains(concat(' ', normalize-space(@class), ' '), ' foo ')]",
            'div>.foo.bar' => "//div/[contains(concat(' ', normalize-space(@class), ' '), ' foo ')][contains(concat(' ', normalize-space(@class), ' '), ' bar ')]",
            'div.bar span' => "//div[contains(concat(' ', normalize-space(@class), ' '), ' bar ')]//span",
            'div > p.first' => "//div/p[contains(concat(' ', normalize-space(@class), ' '), ' first ')]",
            'a[rel="include"]' => "//a[@rel='include']",
            "a[rel='include']" => "//a[@rel='include']",
            'a[rel=include]' => "//a[@rel='include']",

            'p:first' => '//p[1]',
            'p:last' => '//p[last()]',
            'p:even' => '//p[position() mod 2 = 0]',
            'p:odd' => '//p[position() mod 2 = 1]',

            'p:eq(0)' => '//p[1]',
            'p:eq(1)' => '//p[2]',
            'p:eq(-1)' => '//p[last()]',

            'p:gt(0)' => '//p[position()>1]',
            'p:gt(1)' => '//p[position()>2]',
            'p:gt(-1)' => '//p[position()>last()-1]',

            'p:lt(0)' => '//p[position()<1]',
            'p:lt(1)' => '//p[position()<2]',
            'p:lt(-1)' => '//p[position()<last()-1]',

            ':not(li[href="#"])' => "//not(li[@href='#'])",

            ':header' => '//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]',
            ':contains("manaphp")' => "//[contains(.,'manaphp')]",
            ':empty' => '//[not(* or text())]',

            'p:only-child' => '//p[last()=1]',
            'p:first-child' => '//p[position()=1]',
            'p:last-child' => '//p[position()=last()]',
        ];

        $provided = [];
        foreach ($cases as $css => $xpath) {
            $provided[$css] = [$css, $xpath];
        }

        return $provided;
    }
}
