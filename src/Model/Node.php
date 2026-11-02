<?php

namespace Smbpunt\TimelineGraphBundle\Model;

/**
 * A box of the graph: the request, a traced service or an exit point.
 */
final readonly class Node
{
    public const string KIND_REQUEST = 'request';
    public const string KIND_SERVICE = 'service';
    public const string KIND_EXIT = 'exit';

    public const string TYPE_REQUEST = 'Request';
    public const string TYPE_DATABASE = 'Database';
    public const string TYPE_HTTP = 'Http';
    public const string TYPE_LDAP = 'Ldap';
    public const string TYPE_TEMPLATE = 'Template';

    /**
     * @param string  $kind      KIND_*
     * @param string  $type      type folder of the service (Controller, Finder…) or TYPE_* for the request and exit points
     * @param ?string $group     feature of the service, null outside the app
     * @param float   $timeMs    time spent in the box, nested calls included
     * @param float   $share     share of the request time, in %
     * @param int     $callCount number of calls received
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $label,
        public ?string $class,
        public ?string $group,
        public string $type,
        public ?string $detail,
        public float $timeMs,
        public float $share,
        public int $callCount,
        public bool $hasError,
    ) {
    }
}
