<?php

namespace Smbpunt\TimelineGraphBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Smbpunt\TimelineGraphBundle\DataCollector\FlowDataCollector;
use Smbpunt\TimelineGraphBundle\Model\Call;
use Smbpunt\TimelineGraphBundle\Tests\Functional\App\Controller\GreetingController;
use Smbpunt\TimelineGraphBundle\Tests\Functional\App\Greeting\Finder\GreetingFinder;
use Smbpunt\TimelineGraphBundle\Tests\Functional\App\TestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Profiler\Profile;

#[RequiresPhpExtension('opentelemetry')]
final class FlowPanelTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    // The request profile contains the path request → controller → service.
    public function testProfileContainsControllerToServiceFlow(): void
    {
        $client = self::createClient();
        $client->enableProfiler();
        $client->request('GET', '/greeting');

        self::assertResponseIsSuccessful();
        $flow = $this->collector($client)->getFlow();
        self::assertNotNull($flow);

        $edges = array_map(static fn (Call $call): string => $call->from.' → '.$call->to.'::'.$call->method, $flow->calls);
        self::assertContains('request → '.GreetingController::class.'::__invoke', $edges);
        self::assertContains(GreetingController::class.' → '.GreetingFinder::class.'::find', $edges);
    }

    // The Timeline Graph profiler panel shows the graph and loads its scripts.
    public function testPanelRendersFlow(): void
    {
        $client = self::createClient();
        $client->enableProfiler();
        $client->request('GET', '/greeting');
        $token = $this->profile($client)->getToken();

        $crawler = $client->request('GET', '/_profiler/'.$token.'?panel=timeline_graph');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-timeline-graph]');
        self::assertSelectorExists('script[src$="bundles/timelinegraph/flow-player.js"]');
        $data = json_decode($crawler->filter('[data-timeline-graph] script[data-role="data"]')->text(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertNotEmpty($data['calls']);
    }

    // The debug toolbar shows the number of traced calls.
    public function testToolbarShowsCallCount(): void
    {
        $client = self::createClient();
        $client->enableProfiler();
        $client->request('GET', '/greeting');
        $token = $this->profile($client)->getToken();

        $client->request('GET', '/_wdt/'.$token);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sf-toolbar-block-timeline_graph');
        self::assertSelectorTextContains('.sf-toolbar-block-timeline_graph .sf-toolbar-label', 'calls');
    }

    private function collector(KernelBrowser $client): FlowDataCollector
    {
        $collector = $this->profile($client)->getCollector(FlowDataCollector::NAME);
        self::assertInstanceOf(FlowDataCollector::class, $collector);

        return $collector;
    }

    private function profile(KernelBrowser $client): Profile
    {
        $profile = $client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);

        return $profile;
    }
}
