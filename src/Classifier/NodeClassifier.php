<?php

namespace Smbpunt\TimelineGraphBundle\Classifier;

/**
 * Classifies a service: its group is its feature, its type is its type folder.
 *
 * With group_depth 2, App\Catalog\Finder\ProductFinder has group "Catalog" and type "Finder".
 */
final readonly class NodeClassifier
{
    /** Suffixes recognized when the class is not in a type folder. */
    private const array SUFFIXES = [
        'Controller', 'Command', 'Repository', 'Type', 'Voter', 'Listener', 'Subscriber', 'Handler',
        'Builder', 'Finder', 'Resolver', 'Factory', 'Fetcher', 'Client', 'Recorder', 'Exporter',
        'Provider', 'Normalizer', 'Extension', 'Runtime', 'Component',
    ];

    public function __construct(
        private int $groupDepth = 2,
    ) {
    }

    public function group(string $class): string
    {
        $namespace = $this->namespaceSegments($class);
        if ([] === $namespace) {
            return self::shortName($class);
        }

        return $namespace[min($this->groupDepth, \count($namespace)) - 1];
    }

    public function type(string $class): string
    {
        $namespace = $this->namespaceSegments($class);
        if (\count($namespace) > $this->groupDepth) {
            return $namespace[\count($namespace) - 1];
        }

        $shortName = self::shortName($class);
        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($shortName, $suffix) && $shortName !== $suffix) {
                return $suffix;
            }
        }

        return 'Service';
    }

    public static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return false === $position ? $class : substr($class, $position + 1);
    }

    /**
     * @return list<string>
     */
    private function namespaceSegments(string $class): array
    {
        $segments = explode('\\', ltrim($class, '\\'));
        array_pop($segments);

        return $segments;
    }
}
