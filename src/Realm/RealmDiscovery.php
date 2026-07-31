<?php

declare(strict_types=1);

namespace Splicewire\Beam\Realm;

use InvalidArgumentException;
use ReflectionClass;
use Schemastud\Frame\Realm\RealmDefinition;
use Schemastud\Frame\Registry\AdminResourceRegistry;
use Splicewire\Beam\Realm\Attributes\Realm;
use Symfony\Component\Finder\Finder;

/**
 * Boot-time discovery of attributed realms (realm-architecture ticket 08 slice D). Mirrors frame's
 * {@see AdminResourceRegistry::discover()} — a reflect + Finder scan that
 * finds `#[Realm]`-annotated (or preset-subclass: `#[AdminRealm]`/`#[UserRealm]`/`#[TenantRealm]`)
 * classes, projects each into a {@see RealmDefinition}, and `register()`s it onto
 * the {@see RealmRegistry}.
 *
 * ADDITIVE by design: it augments the three imperative base realms the registry ctor already registered
 * — a discovered key absent from the base set self-registers; a discovered key that collides overrides
 * last-wins (exactly like AdminResource). It reads NOTHING from frame beyond the agnostic
 * `RealmDefinition` shape; the realm attribute vocabulary lives entirely in beam.
 */
class RealmDiscovery
{
    public function __construct(private RealmRegistry $registry) {}

    /**
     * Reflect the given explicit realm-marker class-strings and scan the given paths for attributed
     * classes; register a {@see RealmDefinition} for each onto the registry.
     * Idempotent — re-scanning overwrites by key, never duplicates.
     *
     * @param  array<int, class-string>  $classes  explicit realm-marker class list
     * @param  array<int, string>  $paths  filesystem paths to scan for attributed classes
     */
    public function discover(array $classes = [], array $paths = []): void
    {
        foreach ($classes as $class) {
            $this->registerClass($class);
        }

        foreach ($this->scanPaths($paths) as $class) {
            $this->registerClass($class);
        }
    }

    /**
     * Reflect a `#[Realm]`-annotated marker class and register its definition onto the registry.
     *
     * @param  class-string  $class
     */
    public function registerClass(string $class): void
    {
        if (! class_exists($class)) {
            throw new InvalidArgumentException("Realm marker class [{$class}] does not exist.");
        }

        $attribute = $this->readAttribute(new ReflectionClass($class));

        if ($attribute === null) {
            throw new InvalidArgumentException(
                "Class [{$class}] is not annotated with #[Realm] (or a preset subclass); "
                .'use RealmRegistry::register() for attribute-less realms.'
            );
        }

        $this->registry->register($attribute->toDefinition());
    }

    /**
     * Read the first `#[Realm]`-family attribute off a class (preset subclasses match too — the third
     * `getAttributes()` arg follows the class hierarchy).
     */
    private function readAttribute(ReflectionClass $class): ?Realm
    {
        $attrs = $class->getAttributes(Realm::class, \ReflectionAttribute::IS_INSTANCEOF);

        return empty($attrs) ? null : $attrs[0]->newInstance();
    }

    /**
     * Find `#[Realm]`-annotated class-strings under the given paths. Only attributed classes are
     * returned (others are ignored, not errors), so a discover path may point at a whole directory.
     *
     * @param  array<int, string>  $paths
     * @return list<class-string>
     */
    private function scanPaths(array $paths): array
    {
        $existing = array_filter($paths, 'file_exists');

        if (empty($existing)) {
            return [];
        }

        $found = [];

        $finder = (new Finder)->files()->name('*.php')->in($existing);

        foreach ($finder as $file) {
            $class = $this->classNameFromFile($file->getRealPath());

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            if ($this->readAttribute(new ReflectionClass($class)) !== null) {
                $found[] = $class;
            }
        }

        return $found;
    }

    private function classNameFromFile(string $path): ?string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $namespace = preg_match('/namespace\s+([^;]+);/', $contents, $ns) ? trim($ns[1]) : '';

        if (! preg_match('/\bclass\s+(\w+)/', $contents, $cls)) {
            return null;
        }

        return $namespace === '' ? $cls[1] : $namespace.'\\'.$cls[1];
    }
}
