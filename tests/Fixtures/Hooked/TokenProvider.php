<?php

namespace Smbpunt\TimelineGraphBundle\Tests\Fixtures\Hooked;

final class TokenProvider
{
    public function token(): string
    {
        return 'token';
    }
}
