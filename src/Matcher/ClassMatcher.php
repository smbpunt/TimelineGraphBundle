<?php

namespace Smbpunt\TimelineGraphBundle\Matcher;

/**
 * Tells whether a class is traced, based on the include and exclude lists.
 *
 * A pattern ending with "\" is a namespace prefix, "*" matches any characters.
 */
final readonly class ClassMatcher
{
    /** @var list<string> */
    private array $include;

    /** @var list<string> */
    private array $exclude;

    /**
     * @param list<string> $include
     * @param list<string> $exclude
     */
    public function __construct(array $include, array $exclude)
    {
        $this->include = array_map($this->normalize(...), $include);
        $this->exclude = array_map($this->normalize(...), $exclude);
    }

    public function matches(string $class): bool
    {
        $class = ltrim($class, '\\');

        return $this->matchesAny($class, $this->include) && !$this->matchesAny($class, $this->exclude);
    }

    /**
     * @param list<string> $patterns
     */
    private function matchesAny(string $class, array $patterns): bool
    {
        return array_any($patterns, static fn (string $pattern): bool => fnmatch($pattern, $class, \FNM_NOESCAPE));
    }

    private function normalize(string $pattern): string
    {
        $pattern = ltrim($pattern, '\\');

        return str_ends_with($pattern, '\\') ? $pattern.'*' : $pattern;
    }
}
