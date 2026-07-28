<?php

declare(strict_types=1);

namespace Switon\Dev\PhpCsFixer;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\FCT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * Keep #[Autowired] on the same line as the property declaration.
 */
final class InlineAutowiredAttributeFixer extends AbstractFixer
{
    /** @var list<int> */
    private const VISIBILITY = [\T_PRIVATE, \T_PROTECTED, \T_PUBLIC, \T_VAR];

    public function getName(): string
    {
        return 'Switon/inline_autowired_attribute';
    }

    /**
     * Merge multiline `#[Autowired]` onto the property line before `single_space_around_construct`.
     */
    public function getPriority(): int
    {
        return 64;
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            '`#[Autowired]` must be on the same line as the property declaration.',
            [
                new CodeSample(
                    <<<'PHP'
                        <?php
                        class Sample
                        {
                            #[Autowired]
                            protected string $name = '';
                        }
                        PHP,
                ),
            ],
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(FCT::T_ATTRIBUTE);
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        for ($index = $tokens->count() - 1; $index >= 0; --$index) {
            if (!$tokens[$index]->isGivenKind(CT::T_ATTRIBUTE_CLOSE)) {
                continue;
            }

            $attributeStart = $tokens->findBlockStart(Tokens::BLOCK_TYPE_ATTRIBUTE, $index);
            if (!$this->isAutowiredAttribute($tokens, $attributeStart, $index)) {
                continue;
            }

            $propertyIndex = $this->findPropertyDeclarationIndex($tokens, $index);
            if (null === $propertyIndex) {
                continue;
            }

            for ($i = $propertyIndex - 1; $i > $index; --$i) {
                if (!$tokens[$i]->isWhitespace()) {
                    continue;
                }

                $tokens->clearAt($i);
                --$propertyIndex;
            }

            if (!$tokens[$propertyIndex]->isGivenKind(self::VISIBILITY)) {
                continue;
            }

            if ($propertyIndex === $index + 1) {
                $tokens->insertAt($propertyIndex, new Token([\T_WHITESPACE, ' ']));

                continue;
            }

            $whitespaceIndex = $propertyIndex - 1;
            if ($tokens[$whitespaceIndex]->isWhitespace()) {
                $tokens[$whitespaceIndex] = new Token([\T_WHITESPACE, ' ']);
            }
        }
    }

    private function isAutowiredAttribute(Tokens $tokens, int $start, int $end): bool
    {
        $content = '';
        for ($i = $start; $i <= $end; ++$i) {
            $content .= $tokens[$i]->getContent();
        }

        return 1 === preg_match('/#\[\s*Autowired\b/', $content);
    }

    private function findPropertyDeclarationIndex(Tokens $tokens, int $afterAttributeClose): ?int
    {
        $index = $tokens->getNextMeaningfulToken($afterAttributeClose);
        if (null === $index) {
            return null;
        }

        if ($tokens[$index]->isGivenKind(FCT::T_ATTRIBUTE)) {
            return null;
        }

        if (!$tokens[$index]->isGivenKind(self::VISIBILITY)) {
            return null;
        }

        return $index;
    }
}
