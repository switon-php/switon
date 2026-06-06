<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use Switon\Dev\PhpCsFixer\InlineAutowiredAttributeFixer;

require_once __DIR__ . '/scripts/php-cs-fixer/InlineAutowiredAttributeFixer.php';

$finder = Finder::create()
    ->in([
        __DIR__ . '/packages',
        __DIR__ . '/app-admin',
    ])
    ->exclude(['vendor', 'runtime'])
    ->name('*.php');

return (new Config())
    ->registerCustomFixers([new InlineAutowiredAttributeFixer()])
    ->setRiskyAllowed(true)
    ->setUsingCache(false)
    ->setRules([
        '@PSR12' => true,
        'Switon/inline_autowired_attribute' => true,
        'single_space_around_construct' => [
            'constructs_followed_by_a_single_space' => [
                'abstract',
                'as',
                'attribute',
                'case',
                'catch',
                'class',
                'const_import',
                'do',
                'else',
                'elseif',
                'final',
                'finally',
                'for',
                'foreach',
                'function',
                'function_import',
                'if',
                'insteadof',
                'interface',
                'namespace',
                'new',
                'private',
                'protected',
                'public',
                'static',
                'switch',
                'trait',
                'try',
                'use',
                'use_lambda',
                'while',
            ],
            'constructs_preceded_by_a_single_space' => [
                'as',
                'else',
                'elseif',
                'use_lambda',
            ],
        ],
        'no_blank_lines_after_phpdoc' => true,
        'no_empty_phpdoc' => true,
        'phpdoc_indent' => true,
        'phpdoc_no_empty_return' => true,
        'phpdoc_no_useless_inheritdoc' => true,
        'phpdoc_order' => [
            'order' => [
                'alpha',
                'annotation',
                'param',
                'return',
                'throws',
            ],
        ],
        'phpdoc_scalar' => ['types' => ['boolean', 'callback', 'double', 'integer', 'real', 'str']],
        'phpdoc_separation' => true,
        'phpdoc_trim' => true,
        'phpdoc_trim_consecutive_blank_line_separation' => true,
        'phpdoc_var_without_name' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_functions' => true,
            'import_constants' => true,
        ],
    ])
    ->setFinder($finder);
