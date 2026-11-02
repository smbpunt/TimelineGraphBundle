<?php

namespace Smbpunt\TimelineGraphBundle\Tests;

use PHPUnit\Framework\TestCase;
use Smbpunt\TimelineGraphBundle\TimelineGraphBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class TimelineGraphBundleTest extends TestCase
{
    // Bundle name, from which the config key and the Twig namespace derive.
    public function testBundleName(): void
    {
        $bundle = new TimelineGraphBundle();

        self::assertSame('TimelineGraphBundle', $bundle->getName());
        self::assertSame('timeline_graph', $bundle->getContainerExtension()?->getAlias());
    }

    // Outside debug, the bundle declares no service: no hook, no collector.
    public function testNoServiceWithoutDebug(): void
    {
        self::assertFalse($this->load(false)->hasDefinition(TimelineGraphBundle::HOOK_REGISTRAR));
        self::assertFalse($this->load(false)->hasDefinition('.timeline_graph.data_collector'));
    }

    // In debug, services are declared with the default configuration.
    public function testServicesWithDebug(): void
    {
        $container = $this->load(true);

        self::assertTrue($container->hasDefinition(TimelineGraphBundle::HOOK_REGISTRAR));
        self::assertTrue($container->hasDefinition('.timeline_graph.data_collector'));
        self::assertSame(['App\\'], $container->getParameter('timeline_graph.include'));
        self::assertSame(500, $container->getParameter('timeline_graph.max_calls'));
    }

    private function load(bool $debug): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        $container->setParameter('kernel.environment', 'dev');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $extension = new TimelineGraphBundle()->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([], $container);

        return $container;
    }
}
