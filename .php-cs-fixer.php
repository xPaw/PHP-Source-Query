<?php

declare(strict_types=1);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR1' => true,
        '@PSR12' => true,
        '@PSR12:risky' => true,
        '@PSR2' => true,
        '@PhpCsFixer' => true,
        '@PhpCsFixer:risky' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP54Migration' => true,
        '@PHP56Migration:risky' => true,
        '@PHP70Migration' => true,
        '@PHP70Migration:risky' => true,
        '@PHP71Migration' => true,
        '@PHP71Migration:risky' => true,
        '@PHP73Migration' => true,
        '@PHP74Migration' => true,
        '@PHP74Migration:risky' => true,
        '@PHP80Migration' => true,
        '@PHP80Migration:risky' => true,
        '@PHPUnit30Migration:risky' => true,
        '@PHPUnit32Migration:risky' => true,
        '@PHPUnit35Migration:risky' => true,
        '@PHPUnit43Migration:risky' => true,
        '@PHPUnit48Migration:risky' => true,
        '@PHPUnit50Migration:risky' => true,
        '@PHPUnit52Migration:risky' => true,
        '@PHPUnit54Migration:risky' => true,
        '@PHPUnit55Migration:risky' => true,
        '@PHPUnit56Migration:risky' => true,
        '@PHPUnit57Migration:risky' => true,
        '@PHPUnit60Migration:risky' => true,
        '@PHPUnit75Migration:risky' => true,
        '@PHPUnit84Migration:risky' => true,
        'concat_space' => false,
        'native_constant_invocation' => false,
        'native_function_invocation' => false,
        'php_unit_fqcn_annotation' => false,
        'php_unit_test_case_static_method_calls' => false,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->exclude('vendor')
            ->in('Examples')
            ->in('SourceQuery')
            ->in('Tests')
    )
;
