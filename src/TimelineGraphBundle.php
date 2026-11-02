<?php

namespace Smbpunt\TimelineGraphBundle;

use Smbpunt\TimelineGraphBundle\DependencyInjection\Compiler\CollectTracedClassesPass;
use Smbpunt\TimelineGraphBundle\Hook\HookRegistrar;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Timeline Graph profiler panel: how a request flows through the app's services.
 */
final class TimelineGraphBundle extends AbstractBundle
{
    public const string HOOK_REGISTRAR = '.timeline_graph.hook_registrar';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('include')
                    ->info('Traced classes: namespace prefix (ending with \\) or pattern with *.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['App\\'])
                ->end()
                ->arrayNode('exclude')
                    ->info('Excluded classes, same syntax as include.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['App\\Kernel', '*\\Entity\\*', '*\\Dto\\*', '*\\ValueObject\\*', '*\\Enum\\*'])
                ->end()
                ->integerNode('group_depth')
                    ->info('Namespace segment that gives a box its group (2: App\\Catalog\\… is grouped as "Catalog").')
                    ->min(1)
                    ->defaultValue(2)
                ->end()
                ->integerNode('max_calls')
                    ->info('Calls recorded per request, beyond which the flow is truncated.')
                    ->min(1)
                    ->defaultValue(500)
                ->end()
            ->end()
        ;
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Never outside debug: no service, so no hook and no collector.
        if (true !== $builder->getParameter('kernel.debug')) {
            return;
        }

        $container->parameters()
            ->set('timeline_graph.include', $config['include'])
            ->set('timeline_graph.exclude', $config['exclude'])
            ->set('timeline_graph.group_depth', $config['group_depth'])
            ->set('timeline_graph.max_calls', $config['max_calls'])
        ;

        $container->import('../config/services.php');
    }

    public function build(ContainerBuilder $container): void
    {
        // After child definitions and decorators are resolved: classes are known.
        $container->addCompilerPass(new CollectTracedClassesPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    public function boot(): void
    {
        // Hooks must be installed before any traced code runs.
        if (!$this->container instanceof ContainerInterface || !$this->container->has(self::HOOK_REGISTRAR)) {
            return;
        }

        $registrar = $this->container->get(self::HOOK_REGISTRAR);
        if ($registrar instanceof HookRegistrar) {
            $registrar->register();
        }
    }
}
