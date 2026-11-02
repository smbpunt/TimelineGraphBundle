<?php

namespace Smbpunt\TimelineGraphBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Smbpunt\TimelineGraphBundle\DependencyInjection\Compiler\CollectTracedClassesPass;
use Smbpunt\TimelineGraphBundle\Hook\HookRegistrar;
use Smbpunt\TimelineGraphBundle\Recorder\FlowRecorder;
use Smbpunt\TimelineGraphBundle\Tests\Fixtures\Traced\AbstractSampleFinder;
use Smbpunt\TimelineGraphBundle\Tests\Fixtures\Traced\Dto\SampleDto;
use Smbpunt\TimelineGraphBundle\Tests\Fixtures\Traced\SampleCollection;
use Smbpunt\TimelineGraphBundle\Tests\Fixtures\Traced\SampleFinder;
use Smbpunt\TimelineGraphBundle\TimelineGraphBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class CollectTracedClassesPassTest extends TestCase
{
    // Only public methods are traced: no constructor, static, generator,
    // or magic method other than __invoke. An inherited method is listed under its parent class.
    public function testCollectsPublicMethodsByDeclaringClass(): void
    {
        $container = $this->container();

        new CollectTracedClassesPass()->process($container);

        self::assertSame(
            [
                AbstractSampleFinder::class => ['inherited'],
                SampleFinder::class => ['__invoke', 'find'],
            ],
            $container->getDefinition(TimelineGraphBundle::HOOK_REGISTRAR)->getArgument(1),
        );
    }

    // Bundle classes are never traced, even if include covers them.
    public function testNeverTracesBundleClasses(): void
    {
        $container = $this->container();
        $container->setParameter('timeline_graph.include', ['Smbpunt\\']);

        new CollectTracedClassesPass()->process($container);

        $traced = $container->getDefinition(TimelineGraphBundle::HOOK_REGISTRAR)->getArgument(1);
        self::assertIsArray($traced);
        self::assertArrayNotHasKey(FlowRecorder::class, $traced);
        self::assertArrayNotHasKey(HookRegistrar::class, $traced);
        self::assertArrayHasKey(SampleFinder::class, $traced);
    }

    // Without the HookRegistrar service (bundle inactive outside debug), the pass does nothing.
    public function testDoesNothingWithoutRegistrar(): void
    {
        $container = $this->container();
        $container->removeDefinition(TimelineGraphBundle::HOOK_REGISTRAR);

        new CollectTracedClassesPass()->process($container);

        self::assertFalse($container->hasDefinition(TimelineGraphBundle::HOOK_REGISTRAR));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('timeline_graph.include', ['Smbpunt\TimelineGraphBundle\Tests\Fixtures\Traced\\']);
        $container->setParameter('timeline_graph.exclude', ['*\Dto\*']);
        $container->setParameter('sample.class', SampleFinder::class);

        $container->register('.timeline_graph.recorder', FlowRecorder::class);
        $container->register(TimelineGraphBundle::HOOK_REGISTRAR, HookRegistrar::class)
            ->setArguments([new Reference('.timeline_graph.recorder'), []]);

        $container->register('sample', '%sample.class%');
        $container->register('sample_alias_twin', SampleFinder::class);
        $container->register('dto', SampleDto::class);
        $container->register('collection', SampleCollection::class);
        $container->register('outside', \ArrayObject::class);
        $container->setDefinition('abstract', new Definition(SampleFinder::class))->setAbstract(true);

        return $container;
    }
}
