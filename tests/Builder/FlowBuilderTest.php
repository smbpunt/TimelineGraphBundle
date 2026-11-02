<?php

namespace Smbpunt\TimelineGraphBundle\Tests\Builder;

use PHPUnit\Framework\TestCase;
use Smbpunt\TimelineGraphBundle\Builder\FlowBuilder;
use Smbpunt\TimelineGraphBundle\Classifier\NodeClassifier;
use Smbpunt\TimelineGraphBundle\Model\Call;
use Smbpunt\TimelineGraphBundle\Model\Flow;
use Smbpunt\TimelineGraphBundle\Model\Node;
use Smbpunt\TimelineGraphBundle\Recorder\FlowRecorder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FlowBuilderTest extends TestCase
{
    // The flow holds the request, its boxes grouped by feature, and its calls.
    public function testBuildsNodesAndCalls(): void
    {
        $flow = $this->build(static function (FlowRecorder $recorder): void {
            $recorder->enter('App\Catalog\Controller\CatalogController', '__invoke');
            $recorder->enter('App\Catalog\Finder\ProductFinder', 'find');
            $recorder->enterExit(Node::TYPE_DATABASE, 'PostgreSQL', 'executeQuery');
            $recorder->leave();
            $recorder->leave();
            $recorder->leave();
        });

        self::assertSame('GET', $flow->method);
        self::assertSame('/catalog', $flow->path);
        self::assertSame(200, $flow->statusCode);
        self::assertSame(3, $flow->getCallCount());
        self::assertSame(3, $flow->getNodeCount());
        self::assertFalse($flow->hasError);

        $root = $this->node($flow, FlowRecorder::ROOT);
        self::assertSame(Node::KIND_REQUEST, $root->kind);

        $finder = $this->node($flow, 'App\Catalog\Finder\ProductFinder');
        self::assertSame('ProductFinder', $finder->label);
        self::assertSame('Catalog', $finder->group);
        self::assertSame('Finder', $finder->type);
        self::assertGreaterThanOrEqual(0.0, $finder->share);
        self::assertLessThanOrEqual(100.0, $finder->share);

        $database = $this->node($flow, 'exit:Database:PostgreSQL');
        self::assertSame('PostgreSQL', $database->label);
        self::assertNull($database->group);
        self::assertSame(Node::TYPE_DATABASE, $database->type);

        foreach ($flow->calls as $call) {
            self::assertGreaterThanOrEqual($call->startMs, $call->endMs);
            self::assertLessThanOrEqual($flow->durationMs, $call->endMs);
        }
    }

    // Repeated calls are counted, the box gets the total number of calls.
    public function testCountsRepeatedCalls(): void
    {
        $flow = $this->build(static function (FlowRecorder $recorder): void {
            $recorder->enter('App\Catalog\Controller\CatalogController', '__invoke');
            foreach (['find', 'find', 'count'] as $method) {
                $recorder->enter('App\Catalog\Finder\ProductFinder', $method);
                $recorder->leave();
            }
            $recorder->leave();
        });

        $repeats = array_map(static fn (Call $call): string => $call->method.'×'.$call->repeat, \array_slice($flow->calls, 1));
        self::assertSame(['find×2', 'find×2', 'count×1'], $repeats);
        self::assertSame(3, $this->node($flow, 'App\Catalog\Finder\ProductFinder')->callCount);
    }

    // An exception marks the call, the box and the flow as failed.
    public function testErrorsAreReported(): void
    {
        $flow = $this->build(static function (FlowRecorder $recorder): void {
            $recorder->enter('App\Catalog\Finder\ProductFinder', 'find');
            $recorder->leave(new \LogicException());
        });

        self::assertTrue($flow->hasError);
        self::assertSame(\LogicException::class, $flow->calls[0]->error);
        self::assertTrue($this->node($flow, 'App\Catalog\Finder\ProductFinder')->hasError);
    }

    // A 500 response marks the flow as failed, even without a caught exception.
    public function testServerErrorStatus(): void
    {
        $flow = $this->build(static function (): void {}, new Response('', 500));

        self::assertTrue($flow->hasError);
        self::assertSame(0, $flow->getCallCount());
    }

    // A recursive call is not counted twice in the box time.
    public function testRecursiveCallTimeIsNotCountedTwice(): void
    {
        $flow = $this->build(static function (FlowRecorder $recorder): void {
            $recorder->enter('App\Tree\Builder\TreeBuilder', 'build');
            $recorder->enter('App\Tree\Builder\TreeBuilder', 'build');
            usleep(1000);
            $recorder->leave();
            $recorder->leave();
        });

        $outer = $flow->calls[0];
        self::assertEqualsWithDelta($outer->durationMs, $this->node($flow, 'App\Tree\Builder\TreeBuilder')->timeMs, 0.001);
    }

    // The flow serializes (profile) and encodes to JSON (panel).
    public function testFlowIsSerializable(): void
    {
        $flow = $this->build(static function (FlowRecorder $recorder): void {
            $recorder->enter('App\Catalog\Finder\ProductFinder', 'find');
            $recorder->leave();
        });

        self::assertEquals($flow, unserialize(serialize($flow)));
        $json = json_decode(json_encode($flow, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertArrayHasKey('nodes', $json);
        self::assertArrayHasKey('calls', $json);
    }

    /**
     * @param \Closure(FlowRecorder): void $record
     */
    private function build(\Closure $record, ?Response $response = null): Flow
    {
        $recorder = new FlowRecorder();
        $record($recorder);

        return new FlowBuilder(new NodeClassifier(2))->build($recorder, Request::create('/catalog'), $response ?? new Response());
    }

    private function node(Flow $flow, string $id): Node
    {
        foreach ($flow->nodes as $node) {
            if ($node->id === $id) {
                return $node;
            }
        }

        self::fail(\sprintf('Box "%s" missing from the flow.', $id));
    }
}
