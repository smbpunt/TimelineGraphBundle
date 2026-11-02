<?php

namespace Smbpunt\TimelineGraphBundle\DependencyInjection\Compiler;

use Smbpunt\TimelineGraphBundle\Matcher\ClassMatcher;
use Smbpunt\TimelineGraphBundle\TimelineGraphBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Lists traced services and their public methods, then passes them to HookRegistrar.
 *
 * An inherited method is listed under its declaring class, if that class is traced too:
 * the hook on the parent class fires for its children.
 */
final class CollectTracedClassesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(TimelineGraphBundle::HOOK_REGISTRAR)) {
            return;
        }

        /** @var list<string> $include */
        $include = $container->getParameter('timeline_graph.include');
        /** @var list<string> $exclude */
        $exclude = $container->getParameter('timeline_graph.exclude');
        $matcher = new ClassMatcher($include, $exclude);

        /** @var array<string, array<string, string>> $traced methods by declaring class, keyed to deduplicate */
        $traced = [];
        $seen = [];
        foreach ($container->getDefinitions() as $definition) {
            if ($definition->isAbstract() || $definition->isSynthetic()) {
                continue;
            }

            $class = $container->getParameterBag()->resolveValue($definition->getClass());
            if (!\is_string($class) || isset($seen[$class]) || !$matcher->matches($class)) {
                continue;
            }
            $seen[$class] = true;

            $reflection = $container->getReflectionClass($class, false);
            if (!$reflection instanceof \ReflectionClass || $reflection->isInterface() || $reflection->isTrait() || $this->isOwnClass($reflection)) {
                continue;
            }

            foreach ($this->tracedMethods($reflection) as $method) {
                $declaring = $method->getDeclaringClass();
                if ($declaring->getName() === $class || ($matcher->matches($declaring->getName()) && !$this->isOwnClass($declaring))) {
                    $traced[$declaring->getName()][$method->getName()] = $method->getName();
                }
            }
        }

        ksort($traced);

        $container->getDefinition(TimelineGraphBundle::HOOK_REGISTRAR)->replaceArgument(1, array_map(array_values(...), $traced));
    }

    /**
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<\ReflectionMethod>
     */
    private function tracedMethods(\ReflectionClass $reflection): array
    {
        $methods = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // A generator fires the hook on each item read, not on the call: it is not traced.
            if ($method->isStatic() || $method->isAbstract() || $method->isGenerator()) {
                continue;
            }

            // Magic methods are skipped, except __invoke (controllers, handlers).
            if (str_starts_with($method->getName(), '__') && '__invoke' !== $method->getName()) {
                continue;
            }

            $methods[] = $method;
        }

        return $methods;
    }

    /**
     * Bundle classes are never traced: the recorder would trace itself endlessly.
     *
     * @param \ReflectionClass<object> $reflection
     */
    private function isOwnClass(\ReflectionClass $reflection): bool
    {
        $file = $reflection->getFileName();

        return \is_string($file) && str_starts_with($file, \dirname(__DIR__, 2).\DIRECTORY_SEPARATOR);
    }
}
