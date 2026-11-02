<?php

namespace Smbpunt\TimelineGraphBundle\Builder;

use Smbpunt\TimelineGraphBundle\Classifier\NodeClassifier;
use Smbpunt\TimelineGraphBundle\Model\Call;
use Smbpunt\TimelineGraphBundle\Model\Flow;
use Smbpunt\TimelineGraphBundle\Model\Node;
use Smbpunt\TimelineGraphBundle\Recorder\FlowRecorder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds the flow model from the recording.
 */
final readonly class FlowBuilder
{
    public function __construct(
        private NodeClassifier $classifier,
    ) {
    }

    public function build(FlowRecorder $recorder, Request $request, Response $response): Flow
    {
        $origin = $recorder->getOrigin();
        $now = hrtime(true);
        $durationMs = $this->ms($now - $origin);
        $recorded = $recorder->getCalls();

        $repeats = [];
        foreach ($recorded as $call) {
            $key = $this->edgeKey($call['from'], $call['to'], $call['method']);
            $repeats[$key] = ($repeats[$key] ?? 0) + 1;
        }

        $calls = [];
        foreach ($recorded as $id => $call) {
            $startMs = $this->ms($call['start'] - $origin);
            $endMs = $this->ms(($call['end'] ?? $now) - $origin);
            $calls[] = new Call(
                id: $id,
                parentId: $call['parent'],
                from: $call['from'],
                to: $call['to'],
                method: $call['method'],
                startMs: $startMs,
                endMs: $endMs,
                durationMs: round($endMs - $startMs, 3),
                depth: $call['depth'],
                repeat: $repeats[$this->edgeKey($call['from'], $call['to'], $call['method'])],
                error: $call['error'],
                detail: $call['detail'],
            );
        }

        $statusCode = $response->getStatusCode();
        $hasError = $statusCode >= 500 || array_any($calls, static fn (Call $call): bool => null !== $call->error);

        return new Flow(
            method: $request->getMethod(),
            path: $request->getPathInfo(),
            statusCode: $statusCode,
            durationMs: $durationMs,
            nodes: $this->buildNodes($recorder, $calls, $durationMs, $request, $statusCode, $hasError),
            calls: $calls,
            truncated: $recorder->isTruncated(),
            hasError: $hasError,
        );
    }

    /**
     * Boxes linked to at least one call, the request first.
     *
     * @param list<Call> $calls
     *
     * @return list<Node>
     */
    private function buildNodes(FlowRecorder $recorder, array $calls, float $durationMs, Request $request, int $statusCode, bool $hasError): array
    {
        $recordedNodes = $recorder->getNodes();
        $byId = [];
        foreach ($calls as $call) {
            $byId[$call->id] = $call;
        }

        $stats = [];
        foreach ($calls as $call) {
            $stats[$call->to] ??= ['time' => 0.0, 'count' => 0, 'error' => false];
            ++$stats[$call->to]['count'];
            $stats[$call->to]['error'] = $stats[$call->to]['error'] || null !== $call->error;

            // Recursive call (already inside the same box): its time is already counted.
            if (!$this->isInside($call, $byId)) {
                $stats[$call->to]['time'] += $call->durationMs;
            }
        }

        $nodes = [new Node(
            id: FlowRecorder::ROOT,
            kind: Node::KIND_REQUEST,
            label: 'Request',
            class: null,
            group: null,
            type: Node::TYPE_REQUEST,
            detail: \sprintf('%s %s · %d', $request->getMethod(), $request->getPathInfo(), $statusCode),
            timeMs: $durationMs,
            share: 100.0,
            callCount: 1,
            hasError: $hasError,
        )];

        foreach ($stats as $id => $stat) {
            $recorded = $recordedNodes[$id] ?? ['kind' => Node::KIND_EXIT, 'type' => null, 'label' => $id, 'class' => null];
            $class = $recorded['class'];
            $timeMs = round($stat['time'], 3);

            $nodes[] = new Node(
                id: $id,
                kind: $recorded['kind'],
                label: null !== $class ? NodeClassifier::shortName($class) : $recorded['label'],
                class: $class,
                group: null !== $class ? $this->classifier->group($class) : null,
                type: null !== $class ? $this->classifier->type($class) : ($recorded['type'] ?? 'Service'),
                detail: null,
                timeMs: $timeMs,
                share: $durationMs > 0 ? round(min(100.0, $timeMs / $durationMs * 100), 1) : 0.0,
                callCount: $stat['count'],
                hasError: $stat['error'],
            );
        }

        return $nodes;
    }

    /**
     * True if a parent call already targets the same box.
     *
     * @param array<int, Call> $byId
     */
    private function isInside(Call $call, array $byId): bool
    {
        for ($parent = $byId[$call->parentId ?? -1] ?? null; null !== $parent; $parent = $byId[$parent->parentId ?? -1] ?? null) {
            if ($parent->to === $call->to) {
                return true;
            }
        }

        return false;
    }

    private function edgeKey(string $from, string $to, string $method): string
    {
        return $from.'>'.$to.'::'.$method;
    }

    private function ms(int|float $nanoseconds): float
    {
        return round($nanoseconds / 1_000_000, 3);
    }
}
