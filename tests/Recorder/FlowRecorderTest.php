<?php

namespace Smbpunt\TimelineGraphBundle\Tests\Recorder;

use PHPUnit\Framework\TestCase;
use Smbpunt\TimelineGraphBundle\Model\Node;
use Smbpunt\TimelineGraphBundle\Recorder\FlowRecorder;

final class FlowRecorderTest extends TestCase
{
    // A call made during another one starts from the called box, with its parent call.
    public function testNestedCallsKeepCallerAndParent(): void
    {
        $recorder = new FlowRecorder();

        $recorder->enter('App\Catalog\Controller\CatalogController', '__invoke');
        $recorder->enter('App\Catalog\Finder\ProductFinder', 'find');
        $recorder->leave();
        $recorder->leave();

        $calls = $recorder->getCalls();
        self::assertCount(2, $calls);
        self::assertSame(FlowRecorder::ROOT, $calls[0]['from']);
        self::assertSame('App\Catalog\Controller\CatalogController', $calls[0]['to']);
        self::assertNull($calls[0]['parent']);
        self::assertSame('App\Catalog\Controller\CatalogController', $calls[1]['from']);
        self::assertSame('App\Catalog\Finder\ProductFinder', $calls[1]['to']);
        self::assertSame(0, $calls[1]['parent']);
        self::assertSame(1, $calls[1]['depth']);
        self::assertNotNull($calls[1]['end']);
        self::assertSame(0, $recorder->getDepth());
    }

    // The exception that interrupts a call is recorded on that call.
    public function testExceptionIsRecorded(): void
    {
        $recorder = new FlowRecorder();

        $recorder->enter('App\Catalog\Finder\ProductFinder', 'find');
        $recorder->leave(new \RuntimeException());

        self::assertSame(\RuntimeException::class, $recorder->getCalls()[0]['error']);
    }

    // An exit point opens its own frame: a call made during Twig rendering starts from the Twig box.
    public function testExitPointOpensItsOwnFrame(): void
    {
        $recorder = new FlowRecorder();

        $recorder->enter('App\Catalog\Controller\CatalogController', '__invoke');
        $recorder->enterExit(Node::TYPE_TEMPLATE, 'Twig', 'render', 'catalog/index.html.twig');
        $recorder->enter('App\Layout\Menu\Builder\MenuBuilder', 'createMainMenu');
        $recorder->leave();
        $recorder->leave();
        $recorder->leave();

        $calls = $recorder->getCalls();
        self::assertSame('exit:Template:Twig', $calls[1]['to']);
        self::assertSame('catalog/index.html.twig', $calls[1]['detail']);
        self::assertSame('exit:Template:Twig', $calls[2]['from']);
        self::assertSame(Node::KIND_EXIT, $recorder->getNodes()['exit:Template:Twig']['kind']);
    }

    // A decorated HTTP client fires the hook several times: only the outermost call is kept,
    // named after the host found by the innermost client.
    public function testOnlyOutermostHttpCallIsKept(): void
    {
        $recorder = new FlowRecorder();

        $recorder->enter('App\Payment\Client\PaymentClient', 'getPayment');
        $recorder->enterExit(Node::TYPE_HTTP, null, 'request');
        $recorder->enterExit(Node::TYPE_HTTP, null, 'request');
        $recorder->enterExit(Node::TYPE_HTTP, 'payment.example.org', 'request');
        $recorder->leave();
        $recorder->leave();
        $recorder->leave();
        $recorder->leave();

        $calls = $recorder->getCalls();
        self::assertCount(2, $calls);
        self::assertSame('exit:Http:payment.example.org', $calls[1]['to']);
        self::assertSame('payment.example.org', $recorder->getNodes()['exit:Http:payment.example.org']['label']);
        self::assertNotNull($calls[1]['end']);
        self::assertSame(0, $recorder->getDepth());
    }

    // A request to another host made during an HTTP call is kept, as a call from the first host.
    public function testHttpCallToAnotherHostIsKept(): void
    {
        $recorder = new FlowRecorder();

        $recorder->enterExit(Node::TYPE_HTTP, 'api.example.org', 'request');
        $recorder->enterExit(Node::TYPE_HTTP, 'auth.example.org', 'request');
        $recorder->leave();
        $recorder->leave();

        self::assertSame([
            'request → exit:Http:api.example.org::request',
            'exit:Http:api.example.org → exit:Http:auth.example.org::request',
        ], $this->edges($recorder));
    }

    // A call with a relative URL takes the host of its response: a request to another host made
    // inside it is kept, the inner client's request to the same host is merged into it.
    public function testPendingHttpCallTakesHostOfItsResponse(): void
    {
        $recorder = new FlowRecorder();

        $recorder->enter('App\Payment\Client\PaymentClient', 'getPayment');
        $recorder->enterExit(Node::TYPE_HTTP, null, 'request');
        $recorder->enterExit(Node::TYPE_HTTP, 'auth.example.org', 'request');
        $recorder->leave();
        $recorder->enterExit(Node::TYPE_HTTP, 'api.example.org', 'request');
        $recorder->enter('App\Payment\Client\Signer', 'sign');
        $recorder->leave();
        $recorder->leave();
        $recorder->leave(null, 'api.example.org');
        $recorder->leave();

        self::assertSame([
            'request → App\Payment\Client\PaymentClient::getPayment',
            'App\Payment\Client\PaymentClient → exit:Http:api.example.org::request',
            'exit:Http:api.example.org → exit:Http:auth.example.org::request',
            'exit:Http:api.example.org → App\Payment\Client\Signer::sign',
        ], $this->edges($recorder));
        $calls = $recorder->getCalls();
        self::assertSame(1, $calls[2]['parent']);
        self::assertSame(1, $calls[3]['parent']);
        self::assertSame(2, $calls[3]['depth']);
        self::assertSame(0, $recorder->getDepth());
    }

    // Calls nested several levels inside the merged inner call all move up one level.
    public function testMergedInnerCallDescendantsMoveUpOneLevel(): void
    {
        $recorder = new FlowRecorder();

        $recorder->enterExit(Node::TYPE_HTTP, null, 'request');
        $recorder->enterExit(Node::TYPE_HTTP, 'api.example.org', 'request');
        $recorder->enter('App\Signer', 'sign');
        $recorder->enter('App\KeyStore', 'key');
        $recorder->leave();
        $recorder->leave();
        $recorder->leave();
        $recorder->leave(null, 'api.example.org');

        $calls = $recorder->getCalls();
        self::assertSame([null, 0, 1], array_column($calls, 'parent'));
        self::assertSame([0, 1, 2], array_column($calls, 'depth'));
        self::assertSame('exit:Http:api.example.org', $calls[1]['from']);
    }

    // A call made inside an HTTP call with a relative URL starts from the box's final name.
    public function testCallInsidePendingHttpCallStartsFromItsHost(): void
    {
        $recorder = new FlowRecorder();

        $recorder->enterExit(Node::TYPE_HTTP, null, 'request');
        $recorder->enter('App\Auth\TokenProvider', 'token');
        $recorder->leave();
        $recorder->enterExit(Node::TYPE_HTTP, 'api.example.org', 'request');
        $recorder->leave();
        $recorder->leave();

        self::assertSame([
            'request → exit:Http:api.example.org::request',
            'exit:Http:api.example.org → App\Auth\TokenProvider::token',
        ], $this->edges($recorder));
    }

    // Beyond max_calls, nothing more is recorded but the stack stays balanced.
    public function testTruncationKeepsStackBalanced(): void
    {
        $recorder = new FlowRecorder(2);

        $recorder->enter('App\A', 'a');
        $recorder->enter('App\B', 'b');
        $recorder->enter('App\C', 'c');
        $recorder->leave();
        $recorder->leave();
        $recorder->enter('App\D', 'd');
        $recorder->leave();
        $recorder->leave();

        self::assertCount(2, $recorder->getCalls());
        self::assertTrue($recorder->isTruncated());
        self::assertSame(0, $recorder->getDepth());
        self::assertNotNull($recorder->getCalls()[1]['end']);
    }

    // Resetting between two requests clears everything.
    public function testReset(): void
    {
        $recorder = new FlowRecorder(1);
        $recorder->enter('App\A', 'a');
        $recorder->enter('App\B', 'b');

        $recorder->reset();

        self::assertSame([], $recorder->getCalls());
        self::assertSame([], $recorder->getNodes());
        self::assertFalse($recorder->isTruncated());
        self::assertSame(0, $recorder->getDepth());
    }

    // A leave without an enter (hook installed mid-call) breaks nothing.
    public function testLeaveWithoutEnterIsIgnored(): void
    {
        $recorder = new FlowRecorder();

        $recorder->leave();

        self::assertSame([], $recorder->getCalls());
    }

    /**
     * @return list<string>
     */
    private function edges(FlowRecorder $recorder): array
    {
        return array_map(static fn (array $call): string => $call['from'].' → '.$call['to'].'::'.$call['method'], $recorder->getCalls());
    }
}
