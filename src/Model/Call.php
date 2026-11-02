<?php

namespace Smbpunt\TimelineGraphBundle\Model;

/**
 * A call from one box to another. Times are in ms since the start of the request.
 */
final readonly class Call
{
    /**
     * @param ?int    $parentId call running when this one started, null at top level
     * @param int     $repeat   number of calls from the same box to the same box with the same method
     * @param ?string $error    class of the exception that interrupted the call
     * @param ?string $detail   call detail (template name for Twig)
     */
    public function __construct(
        public int $id,
        public ?int $parentId,
        public string $from,
        public string $to,
        public string $method,
        public float $startMs,
        public float $endMs,
        public float $durationMs,
        public int $depth,
        public int $repeat,
        public ?string $error,
        public ?string $detail,
    ) {
    }
}
