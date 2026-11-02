<?php

use Smbpunt\TimelineGraphBundle\Builder\FlowBuilder;
use Smbpunt\TimelineGraphBundle\Classifier\NodeClassifier;
use Smbpunt\TimelineGraphBundle\DataCollector\FlowDataCollector;
use Smbpunt\TimelineGraphBundle\Hook\HookRegistrar;
use Smbpunt\TimelineGraphBundle\Recorder\FlowRecorder;
use Smbpunt\TimelineGraphBundle\TimelineGraphBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('.timeline_graph.recorder', FlowRecorder::class)
        ->args([param('timeline_graph.max_calls')])
        ->tag('kernel.reset', ['method' => 'reset'])
    ;

    // Public: fetched in TimelineGraphBundle::boot(). The 2nd argument is filled by CollectTracedClassesPass.
    $services->set(TimelineGraphBundle::HOOK_REGISTRAR, HookRegistrar::class)
        ->public()
        ->args([service('.timeline_graph.recorder'), []])
    ;

    $services->set('.timeline_graph.node_classifier', NodeClassifier::class)
        ->args([param('timeline_graph.group_depth')])
    ;

    $services->set('.timeline_graph.builder', FlowBuilder::class)
        ->args([service('.timeline_graph.node_classifier')])
    ;

    $services->set('.timeline_graph.data_collector', FlowDataCollector::class)
        ->args([service('.timeline_graph.recorder'), service('.timeline_graph.builder')])
        ->tag('data_collector', [
            'id' => FlowDataCollector::NAME,
            'template' => FlowDataCollector::getTemplate(),
            'priority' => 300,
        ])
    ;
};
