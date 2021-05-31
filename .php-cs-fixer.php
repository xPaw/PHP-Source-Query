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
        'concat_space' => false,
        'native_constant_invocation' => false,
        'native_function_invocation' => false,
        'php_unit_fqcn_annotation' => false,
        'php_unit_test_case_static_method_calls' => false,
    ])
    ->setFinder(PhpCsFixer\Finder::create()
        ->exclude('vendor')
        ->in('Examples')
        ->in('SourceQuery')
        ->in('Tests')
    )
;
