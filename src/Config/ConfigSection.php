<?php

namespace Splicewire\Beam\Config;

use LogicException;
use ReflectionClass;
use ReflectionProperty;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigRoot;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigMerge;
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
     * The walk (config-sections 20; types read by {@see ConfigDataTypes}, the reading Coverage shares):
     * - descends FULLY into the Data class(es) a property holds: a named or nullable type, the Data member(s) of a union
     *   (`Nested|Optional`, `Nested|Optional|null`, `A|B`), the Data member of an intersection (`Nested&Iface`), and
     *   `self`/`parent`, resolved against the declaring class;
     * - descends into the entry class of a map of records (`#[MapValues(Entry::class)]`) or a list of records
     *   (`#[DataCollectionOf(Entry::class)]`) FOR SECRECY ONLY: one segment names the entry (a key or an index), and a
     *   secret declared below RAISES `secret` on the map's own declaration, whose ceiling, merge and effect stand (every
     *   generated entry property carries a bare `#[ConfigKey]`, and {@see ConfigTierBoundary} resolves a map's entries to
     *   the map's declaration);
     * - when two or more Data members disagree, FOLDS them: secret if any member is secret, the STRICTEST ceiling (the
     *   one admitting the fewest tiers), the heaviest effect (deploy over reload over live), merge `extend` if any member
     *   says extend, else `replace` when they differ, and an `env` only when every member names the same one. A member
     *   that does not declare the segment contributes the declaration above it.
     * - does NOT walk: interfaces, untyped properties, an array with no item attribute, and properties only a concrete
     *   subclass of an abstract Data type declares. A secret declared there is not found.
     * Before ticket 20 a union, an intersection and `self`/`parent` ended the walk and maps and lists were never entered, so
     * a secret below them read as plain: stored in plain and served unredacted.
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
     * Whether a pointer passes through a LIST index, read from the DECLARATION (config-sections 20 r5, lead LIST-1745),
     * through {@see ConfigDataTypes}, the reading the other walks share. A segment is a list index only right after a
     * declared list of records (`#[DataCollectionOf]`). A `#[MapValues]` map's key, numeric or not (`/byId/10`), is a
     * stable entry name, and so is a map section's first segment. Where the walk cannot resolve the shape (an undeclared
     * segment, or an array with no item attribute), ANY all-digit segment from there on counts as a list index: the safe
     * direction, since a list index is not a stable identity. With two or more Data members, a list in any of them counts.
     *
     * @param  list<string>  $segments  the pointer's unescaped segments, relative to the root
     */
    public static function throughListIndex(array $segments): bool
    {
        $class = static::class;
        $entries = static::declaration()->entries;

        if ($entries !== null) {
            if ($segments === []) {
                return false;
            }

            array_shift($segments);
            $class = $entries;
        }

        return self::listIndexIn([$class], $segments);
    }

    /**
     * @param  list<class-string>  $classes
     * @param  list<string>  $segments
     */
    private static function listIndexIn(array $classes, array $segments): bool
    {
        if ($segments === []) {
            return false;
        }

        $segment = array_shift($segments);

        foreach ($classes as $class) {
            $property = self::property($class, $segment);

            if ($property === null) {
                if (self::anyDigits([$segment, ...$segments])) {
                    return true;
                }

                continue;
            }

            $nested = ConfigDataTypes::nested($property);

            if ($nested !== []) {
                if (self::listIndexIn($nested, $segments)) {
                    return true;
                }

                continue;
            }

            if (ConfigDataTypes::listEntry($property) !== null) {
                // The next segment is an index into the declared list.
                if ($segments !== []) {
                    return true;
                }

                continue;
            }

            if (($entry = ConfigDataTypes::mapEntry($property)) !== null) {
                // The next segment is a map key (a stable name); below it, the entry class decides.
                if (count($segments) > 1 && self::listIndexIn([$entry], array_slice($segments, 1))) {
                    return true;
                }

                continue;
            }

            // An array with no item attribute, or a scalar: the shape is not declared below here.
            if (self::anyDigits($segments)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $segments */
    private static function anyDigits(array $segments): bool
    {
        foreach ($segments as $segment) {
            if ($segment !== '' && ctype_digit((string) $segment)) {
                return true;
            }
        }

        return false;
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
            $nested = ConfigDataTypes::nested($property);

            if ($nested !== []) {
                $reached[] = self::declarationIn($nested, $segments, $here);

                continue;
            }

            $entry = ConfigDataTypes::mapEntry($property) ?? ConfigDataTypes::listEntry($property);

            // A map or list of records: the next segment names an entry (a key or an index). What the entry class
            // declares below it raises SECRECY only; the map's own ceiling, merge and effect stand.
            if ($entry !== null && count($segments) > 1 && ! $here->secret
                && self::declarationIn([$entry], array_slice($segments, 1), $here)->secret) {
                $here = new ConfigKey($here->ceiling, $here->effect, true, $here->merge, $here->env);
            }

            $reached[] = $here;
        }

        return self::fold($reached);
    }

    /**
     * One declaration from what each Data member of a union reached: the only one, or, when two or more disagree, secret
     * if any is, the strictest ceiling, the heaviest effect, merge `extend` if any says extend (else `replace` when they
     * differ), and an `env` only when every member names the same one (two different variables are not one key's binding).
     *
     * @param  non-empty-list<ConfigKey>  $reached
     */
    private static function fold(array $reached): ConfigKey
    {
        if (count($reached) === 1) {
            return $reached[0];
        }

        $effects = [ConfigEffect::Live, ConfigEffect::Reload, ConfigEffect::Deploy];
        $ceiling = $reached[0]->ceiling;
        $effect = $reached[0]->effect;
        $merges = [];
        $envs = [];
        $secret = false;

        foreach ($reached as $candidate) {
            $secret = $secret || $candidate->secret;
            $ceiling = $candidate->ceiling->rank() < $ceiling->rank() ? $candidate->ceiling : $ceiling;
            $effect = array_search($candidate->effect, $effects, true) > array_search($effect, $effects, true) ? $candidate->effect : $effect;
            $merges[$candidate->merge->value] = $candidate->merge;
            // null (no env) and '' are different declarations: key them apart (Codex r2 LOW).
            $envs[$candidate->env === null ? "\0none" : 'env:'.$candidate->env] = $candidate->env;
        }

        $env = count($envs) === 1 ? reset($envs) : null;

        $merge = match (true) {
            count($merges) === 1 => reset($merges),
            isset($merges[ConfigMerge::Extend->value]) => ConfigMerge::Extend,
            default => ConfigMerge::Replace,
        };

        return new ConfigKey($ceiling, $effect, $secret, $merge, $env);
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
