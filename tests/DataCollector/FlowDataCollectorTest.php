<?php

namespace Smbpunt\TimelineGraphBundle\Tests\DataCollector;

use PHPUnit\Framework\TestCase;
use Smbpunt\TimelineGraphBundle\Builder\FlowBuilder;
use Smbpunt\TimelineGraphBundle\Classifier\NodeClassifier;
use Smbpunt\TimelineGraphBundle\DataCollector\FlowDataCollector;
use Smbpunt\TimelineGraphBundle\Recorder\FlowRecorder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class FlowDataCollectorTest extends TestCase
{
    // A URL with an invalid UTF-8 byte still gives readable JSON for the panel.
    public function testFlowJsonWithInvalidUtf8Path(): void
    {
        $request = Request::create('/');
        $request->server->set('REQUEST_URI', "/caf\xe9");
        $collector = new FlowDataCollector(new FlowRecorder(), new FlowBuilder(new NodeClassifier(2)));
        $collector->collect($request, new Response('', 404));

        $data = json_decode($collector->getFlowJson(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertIsArray($data);
        self::assertSame("/caf\u{FFFD}", $data['path']);
    }
}
