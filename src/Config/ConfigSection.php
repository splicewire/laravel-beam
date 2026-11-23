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
            $here = $attribute !== null ? self::below($declared, $attribute->newInstance()) : $declared;
            $nested = ConfigDataTypes::nested($property);

            if ($nested !== []) {
                $reached[] = self::declarationIn($nested, $segments, $here);

                continue;
            }

            $list = ConfigDataTypes::listEntry($property);

            // A list is one value (config-sections 21: no tier writes into an item), so the list key, and an index into
            // it, is closed to every tier that any key its item class declares is closed to. Otherwise a write or a
            // stored row at the list (or above it) would change a closed field inside an item (Codex review, lane A).
            if ($list !== null && count($segments) <= 1 && ($within = self::closedWithin($list)) !== null && $within !== []) {
                $closed = self::closedUnion([$here->closed, $within]);

                if ($closed !== $here->closed) {
                    $here = new ConfigKey($here->ceiling, $here->effect, $here->secret, $here->merge, $here->env, $closed);
                }
            }

            $entry = ConfigDataTypes::mapEntry($property) ?? $list;

            // A map or list of records: the next segment names an entry (a key or an index). What the entry class
            // declares below it raises SECRECY and CLOSED tiers only (both only ever tighten); the map's own ceiling,
            // merge and effect stand.
            if ($entry !== null && count($segments) > 1) {
                $inner = self::declarationIn([$entry], array_slice($segments, 1), $here);
                $closed = self::closedUnion([$here->closed, $inner->closed]);

                if (($inner->secret && ! $here->secret) || $closed !== $here->closed) {
                    $here = new ConfigKey($here->ceiling, $here->effect, $here->secret || $inner->secret, $here->merge, $here->env, $closed);
                }
            }

            $reached[] = $here;
        }

        return self::fold($reached);
    }

    /**
     * Every tier some key declared in `$class`, or in any record, map entry or list item below it, is closed to; null
     * when none declares `closed:` (a self-referential type is read once).
     *
     * @param  class-string  $class
     * @param  array<class-string, true>  $seen
     * @return list<ConfigTier>|null
     */
    private static function closedWithin(string $class, array $seen = []): ?array
    {
        if (isset($seen[$class]) || ! class_exists($class)) {
            return null;
        }

        $seen[$class] = true;
        $lists = [];

        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $lists[] = ($property->getAttributes(ConfigKey::class)[0] ?? null)?->newInstance()->closed;
            $inner = [...ConfigDataTypes::nested($property), ...array_filter([ConfigDataTypes::mapEntry($property), ConfigDataTypes::listEntry($property)])];

            foreach ($inner as $data) {
                $lists[] = self::closedWithin($data, $seen);
            }
        }

        return self::closedUnion($lists);
    }

    /**
     * The keys below `$segments` that are declared closed to `$tier`, as relative pointers at the HIGHEST such key (closure
     * is sticky), read from the declaration, not from any value (config-sections 17, Codex review lane A). Records are
     * walked into. A list is one key (it carries its items' closure). A map whose entry class closes the tier anywhere is
     * one key too, since its entry names are not declared. The apply side uses it so a stored parent row cannot replace
     * a key the package closed after the row was written, whether the row leaves the key out, nulls it or replaces it.
     *
     * @param  list<string>  $segments  the parent's path below the section root
     * @return list<string>
     */
    public static function closedKeysBelow(array $segments, ConfigTier $tier): array
    {
        $walk = $segments;
        $class = static::class;

        if (static::declaration()->entries !== null) {
            if ($walk === []) {
                return [];
            }

            array_shift($walk);
            $class = static::declaration()->entries;
        }

        $found = [];

        foreach (self::classesAt([$class], $walk) as $data) {
            self::collectClosed($data, $segments, [], $tier, $found, []);
        }

        return array_values(array_unique($found));
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $base
     * @param  list<string>  $path
     * @param  list<string>  $found
     * @param  array<class-string, true>  $seen
     */
    private static function collectClosed(string $class, array $base, array $path, ConfigTier $tier, array &$found, array $seen): void
    {
        if (isset($seen[$class]) || ! class_exists($class)) {
            return;
        }

        $seen[$class] = true;

        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $here = [...$path, $property->getName()];

            if (static::keyDeclaration([...$base, ...$here])->closedTo($tier)) {
                $found[] = JsonPointer::from($here);

                continue;
            }

            foreach (ConfigDataTypes::nested($property) as $data) {
                self::collectClosed($data, $base, $here, $tier, $found, $seen);
            }

            $map = ConfigDataTypes::mapEntry($property);

            if ($map !== null && in_array($tier, self::closedWithin($map) ?? [], true)) {
                $found[] = JsonPointer::from($here);
            }
        }
    }

    /**
     * The Data classes `$segments` reaches from `$classes` (a map key or list index passes into the entry class).
     *
     * @param  list<class-string>  $classes
     * @param  list<string>  $segments
     * @return list<class-string>
     */
    private static function classesAt(array $classes, array $segments): array
    {
        if ($segments === []) {
            return $classes;
        }

        $segment = array_shift($segments);
        $out = [];

        foreach ($classes as $class) {
            $property = self::property($class, $segment);

            if ($property === null) {
                continue;
            }

            $nested = ConfigDataTypes::nested($property);

            if ($nested !== []) {
                array_push($out, ...self::classesAt($nested, $segments));

                continue;
            }

            $entry = ConfigDataTypes::mapEntry($property) ?? ConfigDataTypes::listEntry($property);

            if ($entry !== null && $segments !== []) {
                array_push($out, ...self::classesAt([$entry], array_slice($segments, 1)));
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * A property's own declaration, read below the nearest declaration above it (`$above`). The nearest declaration wins
     * for every slot but two, which only ever tighten on the way down (config-sections 22, 17):
     * - `secret`: a key below a secret declaration is secret, whatever its own `#[ConfigKey]` says. A child's own
     *   attribute (to set its ceiling, say) must not lower the secret an ancestor declared, or a set at the ancestor's
     *   parent would store and serve that child in plain. An explicit `secret: false` there is flagged by the section
     *   audit ({@see ConfigSectionDeclarations});
     * - `closed`: the tiers a key is closed to are the union of its own and every ancestor's, so a child cannot reopen
     *   a tier its object closed.
     */
    private static function below(ConfigKey $above, ConfigKey $own): ConfigKey
    {
        $closed = self::closedUnion([$above->closed, $own->closed]);

        if (($own->secret || ! $above->secret) && $closed === $own->closed) {
            return $own;
        }

        return new ConfigKey($own->ceiling, $own->effect, $own->secret || $above->secret, $own->merge, $own->env, $closed);
    }

    /**
     * The union of closed-tier lists, in tier order; null only when every one is null (nothing declared either way).
     *
     * @param  list<list<ConfigTier>|null>  $lists
     * @return list<ConfigTier>|null
     */
    private static function closedUnion(array $lists): ?array
    {
        $declared = array_filter($lists, fn (?array $list): bool => $list !== null);

        if ($declared === []) {
            return null;
        }

        if (count($declared) === 1) {
            return reset($declared);
        }

        $tiers = [];

        foreach ($declared as $list) {
            foreach ($list as $tier) {
                $tiers[$tier->value] = $tier;
            }
        }

        $tiers = array_values($tiers);
        usort($tiers, fn (ConfigTier $a, ConfigTier $b): int => $a->rank() <=> $b->rank());

        return $tiers;
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

        // Closed tiers fold like secrecy: a tier any member closes is closed (config-sections 17).
        return new ConfigKey($ceiling, $effect, $secret, $merge, $env, self::closedUnion(array_map(fn (ConfigKey $key): ?array => $key->closed, $reached)));
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
