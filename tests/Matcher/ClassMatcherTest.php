<?php

namespace Smbpunt\TimelineGraphBundle\Tests\Matcher;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Smbpunt\TimelineGraphBundle\Matcher\ClassMatcher;
use Symfony\Component\HttpKernel\HttpKernel;

final class ClassMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function classes(): iterable
    {
        yield 'app controller' => ['App\Catalog\Controller\CatalogController', true];
        yield 'app service' => ['App\Catalog\Finder\ProductFinder', true];
        yield 'kernel' => ['App\Kernel', false];
        yield 'entity' => ['App\Catalog\Entity\Product', false];
        yield 'DTO' => ['App\Catalog\Dto\ProductFilter', false];
        yield 'value object' => ['App\Order\ValueObject\Money', false];
        yield 'enum' => ['App\Catalog\Enum\ProductStatus', false];
        yield 'outside the app' => [HttpKernel::class, false];
        yield 'prefix without separator' => ['Application\Foo', false];
        yield 'leading backslash' => ['\App\Catalog\Finder\ProductFinder', true];
    }

    // App classes are traced, except the kernel and data objects.
    #[DataProvider('classes')]
    public function testDefaultRules(string $class, bool $expected): void
    {
        $matcher = new ClassMatcher(['App\\'], ['App\Kernel', '*\Entity\*', '*\Dto\*', '*\ValueObject\*', '*\Enum\*']);

        self::assertSame($expected, $matcher->matches($class));
    }

    // A wildcard pattern works for both include and exclude.
    public function testWildcardInclude(): void
    {
        $matcher = new ClassMatcher(['*\Finder\*'], ['*Legacy*']);

        self::assertTrue($matcher->matches('App\Catalog\Finder\ProductFinder'));
        self::assertFalse($matcher->matches('App\Catalog\Finder\LegacyFinder'));
        self::assertFalse($matcher->matches('App\Catalog\Controller\CatalogController'));
    }
}
