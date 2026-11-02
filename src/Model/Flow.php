<?php

namespace Smbpunt\TimelineGraphBundle\Model;

/**
 * Flow of a request: the boxes and the calls between them.
 */
final readonly class Flow
{
    /**
     * @param list<Node> $nodes
     * @param list<Call> $calls
     * @param bool       $truncated true if max_calls was reached
     */
    public function __construct(
        public string $method,
        public string $path,
        public int $statusCode,
        public float $durationMs,
        public array $nodes,
        public array $calls,
        public bool $truncated,
        public bool $hasError,
    ) {
    }

    public function getCallCount(): int
    {
        return \count($this->calls);
    }

    /**
     * Number of boxes, not counting the request.
     */
    public function getNodeCount(): int
    {
        return \count($this->nodes) - 1;
    }
}
