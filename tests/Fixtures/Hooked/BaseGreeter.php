<?php

namespace Smbpunt\TimelineGraphBundle\Tests\Fixtures\Hooked;

class BaseGreeter
{
    public function greet(): string
    {
        return 'Hello';
    }

    public function farewell(): string
    {
        return 'Goodbye';
    }
}
