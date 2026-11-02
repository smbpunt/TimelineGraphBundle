<?php

namespace Smbpunt\TimelineGraphBundle\DataCollector;

use Smbpunt\TimelineGraphBundle\Builder\FlowBuilder;
use Smbpunt\TimelineGraphBundle\Hook\HookRegistrar;
use Smbpunt\TimelineGraphBundle\Model\Flow;
use Smbpunt\TimelineGraphBundle\Recorder\FlowRecorder;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Timeline Graph panel data: the request flow, frozen at kernel.response.
 */
final class FlowDataCollector extends AbstractDataCollector
{
    public const string NAME = 'timeline_graph';

    public function __construct(
        private readonly FlowRecorder $recorder,
        private readonly FlowBuilder $builder,
    ) {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
        $this->data = [
            'available' => HookRegistrar::isAvailable(),
            'flow' => $this->builder->build($this->recorder, $request, $response),
        ];
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public static function getTemplate(): string
    {
        return '@TimelineGraph/Collector/flow.html.twig';
    }

    /**
     * False if the opentelemetry extension is missing: no call is traced.
     */
    public function isAvailable(): bool
    {
        return true === ($this->data['available'] ?? false);
    }

    public function getFlow(): ?Flow
    {
        $flow = $this->data['flow'] ?? null;

        return $flow instanceof Flow ? $flow : null;
    }

    /**
     * The flow as JSON for the panel's JS, safe to put in a script tag.
     */
    public function getFlowJson(): string
    {
        return json_encode($this->getFlow(), \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION);
    }
}
