<?php

namespace Splicewire\Beam\Config;

use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * The typed description of one config root (ADR-0245): which keys it has, their shapes, and how each behaves on
 * the Override Ladder. A package (or host) declares one per root:
 *
 *     #[ConfigRoot(root: 'beam.core', title: 'Beam core')]
 *     class BeamCoreSection extends ConfigSection
 *     {
 *         public function __construct(
 *             #[Title('Route prefix'), ConfigKey(ceiling: ConfigTier::Operator, effect: ConfigEffect::Reload)]
 *             public string $prefix,
 *         ) {}
 *     }
 *
 * Properties are named exactly as the config keys they describe (`unit_cost_usd`, not `unitCostUsd`): the
 * section's JSON Schema, its pointers and the config file then spell every key one way, with no name mapper
 * between them to drift.
 *
 * A map-shaped root (`app.meters`, keyed by names a host chooses) declares `entries:` on {@see ConfigRoot} and no
 * properties; each value is one entry class instance.
 *
 * The class is vocabulary. It never holds a host's values: those are read from `config()` and the config files
 * by {@see ConfigSectionReader}, and per-tenant values never become registry entries (the registry-kernel rule).
 */
abstract class ConfigSection extends Data
{
    public static function declaration(): ConfigRoot
    {
        $attributes = (new ReflectionClass(static::class))->getAttributes(ConfigRoot::class);

        if ($attributes === []) {
            throw new LogicException(sprintf('Config section [%s] declares no #[ConfigRoot].', static::class));
        }

        return $attributes[0]->newInstance();
    }

    public static function root(): string
    {
        return static::declaration()->root;
    }

    /**
     * The `#[ConfigKey]` declared for the key a pointer addresses, or the conservative default when the key, or
     * any key above it, declares none. The NEAREST declaration on the path wins, so a key nested under a
     * declared map inherits that map's declaration.
     *
     * The walk descends into the Data class a property holds: its named type (`Nested`, `?Nested`), or the Data
     * member(s) of a union (`Nested|Optional`, `Nested|Optional|null`; `Optional`, null and scalars hold no keys). When a
     * union holds TWO or more Data classes, each is walked and the result FAILS TOWARDS SECRET: a key secret in any member
     * is secret (config-sections 20; before it, a union ended the walk, so a secret below `Nested|Optional` read as plain
     * and was stored and served unredacted).
     *
     * @param  list<string>  $segments  the pointer's unescaped segments, relative to the root
     */
    public static function keyDeclaration(array $segments): ConfigKey
    {
        $class = static::class;
        $entries = static::declaration()->entries;

        if ($entries !== null) {
            // A map's first segment is an entry name, which declares nothing.
            array_shift($segments);
            $class = $entries;
        }

        return self::declarationIn([$class], $segments, new ConfigKey);
    }

    /**
     * The declaration `$segments` reaches from any of `$classes` (the Data classes the walk is in), starting from
     * `$declared` (the nearest declaration above). A segment a class does not declare ends that branch at `$declared`.
     *
     * @param  list<class-string>  $classes
     * @param  list<string>  $segments
     */
    private static function declarationIn(array $classes, array $segments, ConfigKey $declared): ConfigKey
    {
        if ($segments === [] || $classes === []) {
            return $declared;
        }

        $segment = array_shift($segments);
        $reached = [];

        foreach ($classes as $class) {
            $property = self::property($class, $segment);

            if ($property === null) {
                $reached[] = $declared;

                continue;
            }

            $attribute = $property->getAttributes(ConfigKey::class)[0] ?? null;
            $here = $attribute !== null ? $attribute->newInstance() : $declared;
            $reached[] = self::declarationIn(self::dataClasses($property), $segments, $here);
        }

        foreach ($reached as $candidate) {
            if ($candidate->secret) {
                return $candidate;
            }
        }

        return $reached[0];
    }

    /**
     * The Data classes a property holds: its named type, or every Data member of a union or nullable type.
     *
     * @return list<class-string<Data>>
     */
    private static function dataClasses(ReflectionProperty $property): array
    {
        $type = $property->getType();
        $members = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        $data = [];

        foreach ($members as $member) {
            if ($member instanceof ReflectionNamedType && ! $member->isBuiltin() && is_subclass_of($member->getName(), Data::class)) {
                $data[] = $member->getName();
            }
        }

        return $data;
    }

    /**
     * Every class whose properties declare keys of this section: the section itself, or its entry class.
     *
     * @return class-string<Data>
     */
    public static function keyClass(): string
    {
        return static::declaration()->entries ?? static::class;
    }

    /** @param class-string $class */
    private static function property(string $class, string $name): ?ReflectionProperty
    {
        $reflection = new ReflectionClass($class);

        return $reflection->hasProperty($name) ? $reflection->getProperty($name) : null;
    }
}
