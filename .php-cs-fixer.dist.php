<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

// Symfony conventions first: the @Symfony sets come after the PHP migration sets
// to have the last word (e.g. @Symfony:risky removes declare(strict_types=1)).
return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PHP8x4Migration' => true,
        '@PHP8x4Migration:risky' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHPUnit11x0Migration:risky' => true,
    ])
    ->setFinder(
        (new Finder())
            ->in([__DIR__.'/config', __DIR__.'/src', __DIR__.'/tests'])
    )
;
