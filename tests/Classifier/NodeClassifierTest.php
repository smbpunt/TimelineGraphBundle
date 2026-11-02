<?php

namespace Smbpunt\TimelineGraphBundle\Tests\Classifier;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Smbpunt\TimelineGraphBundle\Classifier\NodeClassifier;

final class NodeClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function classes(): iterable
    {
        yield 'controller' => ['App\Catalog\Controller\CatalogController', 'Catalog', 'Controller'];
        yield 'finder' => ['App\Catalog\Finder\ProductFinder', 'Catalog', 'Finder'];
        yield 'sub-feature' => ['App\Layout\Menu\Builder\MenuBuilder', 'Layout', 'Builder'];
        yield 'no type folder, known suffix' => ['App\Catalog\ProductFinder', 'Catalog', 'Finder'];
        yield 'no type folder, no suffix' => ['App\Catalog\Toolbox', 'Catalog', 'Service'];
        yield 'namespace root' => ['App\Toolbox', 'App', 'Service'];
    }

    // The group is the feature, the type is the type folder or, failing that, the class suffix.
    #[DataProvider('classes')]
    public function testGroupAndType(string $class, string $group, string $type): void
    {
        $classifier = new NodeClassifier(2);

        self::assertSame($group, $classifier->group($class));
        self::assertSame($type, $classifier->type($class));
    }

    // group_depth picks the namespace segment that gives the group.
    public function testGroupDepth(): void
    {
        $classifier = new NodeClassifier(3);

        self::assertSame('Menu', $classifier->group('App\Layout\Menu\Builder\MenuBuilder'));
        self::assertSame('Builder', $classifier->type('App\Layout\Menu\Builder\MenuBuilder'));
    }

    // The short name is the class name without its namespace.
    public function testShortName(): void
    {
        self::assertSame('ProductFinder', NodeClassifier::shortName('App\Catalog\Finder\ProductFinder'));
        self::assertSame('Toolbox', NodeClassifier::shortName('Toolbox'));
    }
}
