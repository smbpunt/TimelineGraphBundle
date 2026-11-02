<?php

namespace Smbpunt\TimelineGraphBundle\Tests\Functional\App\Greeting\Finder;

final readonly class GreetingFinder
{
    public function find(string $name): string
    {
        return 'Hello '.$name;
    }
}
