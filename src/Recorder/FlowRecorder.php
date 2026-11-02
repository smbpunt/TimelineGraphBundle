<?php

namespace Smbpunt\TimelineGraphBundle\Recorder;

use Smbpunt\TimelineGraphBundle\Model\Node;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Records calls during the request: stack of running calls and list of calls.
 *
 * Beyond max_calls, nothing more is recorded but the stack stays consistent.
 *
 * @phpstan-type RecordedCall array{from: string, to: string, method: string, parent: ?int, depth: int, start: int, end: ?int, error: ?string, detail: ?string}
 * @phpstan-type RecordedNode array{kind: string, type: ?string, label: string, class: ?string}
 */
final class FlowRecorder implements ResetInterface
{
    /** Starting box: the request itself. */
    public const string ROOT = 'request';

    /** @var list<array{node: string, call: ?int, exit: ?string, pending: bool, nested: bool}> */
    private array $stack = [];

    /** @var list<RecordedCall> */
    private array $calls = [];

    /** @var array<string, RecordedNode> */
    private array $nodes = [];

    /** @var array<int, true> calls merged into their parent (inner client of a decorated HTTP client) */
    private array $absorbed = [];

    private bool $truncated = false;

    private int $origin;

    public function __construct(
        private readonly int $maxCalls = 500,
    ) {
        $this->origin = hrtime(true);
    }

    /**
     * Entering a method of a traced service.
     */
    public function enter(string $class, string $method): void
    {
        $this->nodes[$class] ??= ['kind' => Node::KIND_SERVICE, 'type' => null, 'label' => $class, 'class' => $class];
        $this->push($class, $method, null, null, false);
    }

    /**
     * Entering an exit point (database, HTTP, LDAP, Twig).
     *
     * If the same exit point is already running just above (decorated HTTP client, nested render),
     * only the outermost call is kept. A call to another target (another host) is recorded as a child call.
     * Under a box with no name yet, a named call is recorded too: when the box is left,
     * the call that gave it its name is merged into it (see leave()).
     *
     * @param ?string $label box name, null if not known yet (relative URL)
     */
    public function enterExit(string $type, ?string $label, string $method, ?string $detail = null): void
    {
        $key = $this->exitKey($type, $label);
        $top = array_key_last($this->stack);
        if (null !== $top && $this->stack[$top]['exit'] === $type) {
            $frame = $this->stack[$top];
            $sameTarget = $frame['pending'] ? null === $label : (null === $label || $frame['node'] === $key);
            if ($sameTarget) {
                $this->stack[] = [...$frame, 'nested' => true];

                return;
            }
        }

        $this->nodes[$key] ??= ['kind' => Node::KIND_EXIT, 'type' => $type, 'label' => $label ?? $type, 'class' => null];
        $this->push($key, $method, $type, $detail, null === $label);
    }

    /**
     * Entering a call that is not recorded: calls made during it start from the current box.
     * Its leave() is still expected.
     */
    public function enterUnrecorded(): void
    {
        $top = array_key_last($this->stack);
        $this->stack[] = null === $top
            ? ['node' => self::ROOT, 'call' => null, 'exit' => null, 'pending' => false, 'nested' => true]
            : [...$this->stack[$top], 'nested' => true];
    }

    /**
     * Leaving the current call, with the exception that interrupts it, if any.
     *
     * @param ?string $label name of the box, if only known now (host of the returned HTTP response)
     */
    public function leave(?\Throwable $exception = null, ?string $label = null): void
    {
        $frame = array_pop($this->stack);
        if (null === $frame || null === $frame['call'] || $frame['nested']) {
            return;
        }

        $this->calls[$frame['call']]['end'] = hrtime(true);
        if ($exception instanceof \Throwable) {
            $this->calls[$frame['call']]['error'] = $exception::class;
        }

        if ($frame['pending'] && null !== $frame['exit']) {
            $this->resolvePending($frame['call'], $frame['exit'], $label);
        }
    }

    public function reset(): void
    {
        $this->stack = [];
        $this->calls = [];
        $this->nodes = [];
        $this->absorbed = [];
        $this->truncated = false;
        $this->origin = hrtime(true);
    }

    /**
     * @return list<RecordedCall>
     */
    public function getCalls(): array
    {
        if ([] === $this->absorbed) {
            return $this->calls;
        }

        // Ids are positions in the list: renumber them without the merged calls.
        $ids = [];
        $calls = [];
        foreach ($this->calls as $id => $call) {
            if (isset($this->absorbed[$id])) {
                continue;
            }

            $ids[$id] = \count($calls);
            $calls[] = ['parent' => null === $call['parent'] ? null : $ids[$call['parent']]] + $call;
        }

        return $calls;
    }

    /**
     * @return array<string, RecordedNode>
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    public function getDepth(): int
    {
        return \count($this->stack);
    }

    /**
     * Start of the recording (hrtime, in ns).
     */
    public function getOrigin(): int
    {
        return $this->origin;
    }

    private function push(string $node, string $method, ?string $exit, ?string $detail, bool $pending): void
    {
        $top = array_key_last($this->stack);
        $parent = null === $top ? null : $this->stack[$top];

        if (\count($this->calls) >= $this->maxCalls) {
            $this->truncated = true;
            $this->stack[] = ['node' => $node, 'call' => null, 'exit' => $exit, 'pending' => false, 'nested' => false];

            return;
        }

        $id = \count($this->calls);
        $this->calls[] = [
            'from' => $parent['node'] ?? self::ROOT,
            'to' => $node,
            'method' => $method,
            'parent' => $parent['call'] ?? null,
            'depth' => \count($this->stack),
            'start' => hrtime(true),
            'end' => null,
            'error' => null,
            'detail' => $detail,
        ];
        $this->stack[] = ['node' => $node, 'call' => $id, 'exit' => $exit, 'pending' => $pending, 'nested' => false];
    }

    /**
     * Gives its real name to the box opened without one (relative URL): the host of the returned response,
     * or else the host of the last call of the same method made inside it by a decorated client.
     * That inner call is the same request seen one level down: it is merged into the box.
     */
    private function resolvePending(int $id, string $type, ?string $label): void
    {
        $method = $this->calls[$id]['method'];
        $inner = null;
        for ($i = \count($this->calls) - 1; $i > $id; --$i) {
            $call = $this->calls[$i];
            if ($call['parent'] === $id && !isset($this->absorbed[$i]) && $call['method'] === $method
                && ($this->nodes[$call['to']]['type'] ?? null) === $type
                && (null === $label || $call['to'] === $this->exitKey($type, $label))) {
                $inner = $i;
                break;
            }
        }

        $label ??= null !== $inner ? $this->nodes[$this->calls[$inner]['to']]['label'] : null;
        if (null === $label) {
            return;
        }

        $key = $this->exitKey($type, $label);
        $this->nodes[$key] ??= ['kind' => Node::KIND_EXIT, 'type' => $type, 'label' => $label, 'class' => null];
        $this->calls[$id]['to'] = $key;

        if (null !== $inner) {
            $this->absorbed[$inner] = true;
        }

        $count = \count($this->calls);

        // Calls made inside the merged call move up one level.
        // Done before re-parenting, which would cut the path from grandchildren to the merged call.
        if (null !== $inner) {
            for ($i = $inner + 1; $i < $count; ++$i) {
                if ($this->descendsFrom($i, $inner)) {
                    --$this->calls[$i]['depth'];
                }
            }
        }

        // Calls made inside the box start from its real name; those of the merged call are attached to the box.
        for ($i = $id + 1; $i < $count; ++$i) {
            if ($this->calls[$i]['parent'] === $id) {
                $this->calls[$i]['from'] = $key;
            } elseif (null !== $inner && $this->calls[$i]['parent'] === $inner) {
                $this->calls[$i]['parent'] = $id;
            }
        }
    }

    private function descendsFrom(int $id, int $ancestor): bool
    {
        for ($parent = $this->calls[$id]['parent']; null !== $parent && $parent >= $ancestor; $parent = $this->calls[$parent]['parent']) {
            if ($parent === $ancestor) {
                return true;
            }
        }

        return false;
    }

    private function exitKey(string $type, ?string $label): string
    {
        return 'exit:'.$type.(null === $label ? '' : ':'.$label);
    }
}
