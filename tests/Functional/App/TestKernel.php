<?php

namespace Smbpunt\TimelineGraphBundle\Tests\Functional\App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Minimal app: a controller that calls a service, and the profiler.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct()
    {
        parent::__construct('test', true);
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return \dirname(__DIR__, 3).'/var/cache';
    }

    public function getLogDir(): string
    {
        return \dirname(__DIR__, 3).'/var/log';
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'profiler' => ['enabled' => true, 'collect' => true],
            'router' => ['utf8' => true],
        ]);
        $container->extension('web_profiler', ['toolbar' => false]);
        $container->extension('timeline_graph', [
            'include' => ['Smbpunt\TimelineGraphBundle\Tests\Functional\App\\'],
            'exclude' => [self::class],
            'group_depth' => 6,
        ]);

        $container->services()
            ->defaults()->autowire()->autoconfigure()
            ->load('Smbpunt\TimelineGraphBundle\Tests\Functional\App\\', __DIR__)
                ->exclude([__DIR__.'/TestKernel.php', __DIR__.'/config'])
        ;
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@WebProfilerBundle/Resources/config/routing/wdt.php')->prefix('/_wdt');
        $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.php')->prefix('/_profiler');
        $routes->import(__DIR__.'/Controller/', 'attribute');
    }
}
