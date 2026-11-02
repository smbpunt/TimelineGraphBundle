<?php

namespace Smbpunt\TimelineGraphBundle\Tests\Fixtures\Hooked;

final class FormalGreeter extends BaseGreeter
{
    public function greet(): string
    {
        return 'Hello madam';
    }
}
