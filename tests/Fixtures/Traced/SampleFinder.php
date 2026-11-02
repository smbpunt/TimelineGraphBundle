<?php

namespace Smbpunt\TimelineGraphBundle\Tests\Fixtures\Traced;

class SampleFinder extends AbstractSampleFinder implements \Stringable
{
    public function __invoke(): void
    {
    }

    public function find(): void
    {
    }

    /**
     * @return iterable<int>
     */
    public function rows(): iterable
    {
        yield 1;
    }

    public static function create(): self
    {
        return new self();
    }

    public function __toString(): string
    {
        return 'sample';
    }

    protected function helper(): void
    {
    }
}
