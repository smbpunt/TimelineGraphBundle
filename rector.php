<?php

use Rector\Config\RectorConfig;
use Rector\Symfony\CodeQuality\Rector\BinaryOp\ResponseStatusCodeRector;
use Rector\Symfony\Symfony40\Rector\MethodCall\ContainerBuilderCompileEnvArgumentRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        // Targets application controllers, irrelevant here; crashes on first-class callables (Rector 2.5)
        ResponseStatusCodeRector::class,
        // Symfony conventions: no declare(strict_types=1)
        SafeDeclareStrictTypesRector::class,
        // Symfony 3 → 4 migration (old compile() default), irrelevant on Symfony 8
        ContainerBuilderCompileEnvArgumentRector::class,
    ])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        symfonyCodeQuality: true,
    )
    ->withComposerBased(phpunit: true, symfony: true)
    ->withAttributesSets(all: true)
    ->withImportNames(importShortClasses: false, removeUnusedImports: true);
