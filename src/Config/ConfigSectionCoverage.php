<?php

namespace Splicewire\Beam\Config;

use ReflectionClass;
use ReflectionProperty;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\MapValues;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigDraft;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigOpaque;
use Splicewire\Beam\Enums\ConfigEffect;

/**
 * Which parts of a root's values a Config Section describes (config-sections ticket 06), shared by
 * {@see \Splicewire\Beam\Doctor\ConfigSectionCoverageAudit} and the generator's own check.
 *
 * A key is COVERED when its property chain reaches it: a property of the section (or of a nested Data class, or of a
 * map's entry class named by `entries:` or `#[MapValues(Entry::class)]`), or anything below a `#[ConfigOpaque]`
 * property. A list or a scalar is one value, covered by its property. A key no property names is reported once, at the
 * highest uncovered pointer; its children are not listed again.
 */
class ConfigSectionCoverage
{
    /**
     * @param  class-string<ConfigSection>  $section
     * @param  array<array-key, mixed>  $values  the merged values of the section's root
     * @return list<string> the uncovered JSON pointers
     */
    public static function uncovered(string $section, array $values): array
    {
        $uncovered = [];
        $entries = $section::declaration()->entries;

        if ($entries !== null) {
            foreach ($values as $name => $entry) {
                if (is_array($entry) && ! array_is_list($entry)) {
                    self::walk($entries, $entry, [(string) $name], $uncovered);
                }
            }

            return $uncovered;
        }

        self::walk($section, $values, [], $uncovered);

        return $uncovered;
    }

    /**
     * Every key whose description is missing or still the generator's harvested draft, by pointer (`*` for a map entry).
     *
     * @param  class-string<ConfigSection>  $section
     * @return list<string>
     */
    public static function drafts(string $section): array
    {
        $drafts = [];
        $entries = $section::declaration()->entries;
        self::draftsIn($entries ?? $section, $entries === null ? [] : ['*'], $drafts, []);

        return $drafts;
    }

    /**
     * Every key declared `live`, by pointer relative to the root (`*` for a map entry name). A live key must not be read
     * while the application boots: a value read then is held past the overlay's next sync.
     *
     * @param  class-string<ConfigSection>  $section
     * @return list<string>
     */
    public static function liveKeys(string $section): array
    {
        $live = [];
        $entries = $section::declaration()->entries;
        self::liveIn($entries ?? $section, $entries === null ? [] : ['*'], $live, []);

        return $live;
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $path
     * @param  list<string>  $live
     * @param  array<class-string, true>  $seen
     */
    private static function liveIn(string $class, array $path, array &$live, array $seen): void
    {
        if (isset($seen[$class])) {
            return;
        }

        $seen[$class] = true;

        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $here = [...$path, $property->getName()];
            $key = ($property->getAttributes(ConfigKey::class)[0] ?? null)?->newInstance();

            if ($key?->effect === ConfigEffect::Live) {
                $live[] = JsonPointer::from($here);
            }

            if (($nested = self::dataClass($property)) !== null) {
                self::liveIn($nested, $here, $live, $seen);
            } elseif (($entry = self::mapEntry($property)) !== null) {
                self::liveIn($entry, [...$here, '*'], $live, $seen);
            }
        }
    }

    /**
     * @param  class-string  $class
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $path
     * @param  list<string>  $uncovered
     */
    private static function walk(string $class, array $values, array $path, array &$uncovered): void
    {
        // The config key is the WIRE name; a mapped key (Spatie `#[MapName]`) is named by a differently-spelled
        // property. Resolved through the one shared wire-name source, so coverage and the committed schema agree on
        // what a key is called (ticket 13, Part 2, condition 2).
        $byWire = ConfigWireNames::map($class);

        foreach ($values as $name => $value) {
            $here = [...$path, (string) $name];

            if (! is_string($name) || ! isset($byWire[$name])) {
                $uncovered[] = JsonPointer::from($here);

                continue;
            }

            $property = $byWire[$name][0];

            if ($property->getAttributes(ConfigOpaque::class) !== [] || ! is_array($value) || $value === [] || array_is_list($value)) {
                continue;
            }

            $nested = self::dataClass($property);

            if ($nested !== null) {
                self::walk($nested, $value, $here, $uncovered);

                continue;
            }

            $entry = self::mapEntry($property);

            if ($entry !== null) {
                foreach ($value as $key => $item) {
                    if (is_array($item) && ! array_is_list($item)) {
                        self::walk($entry, $item, [...$here, (string) $key], $uncovered);
                    }
                }

                continue;
            }

            // A typed map of scalars describes its values; anything else with keys of its own is undescribed.
            $map = $property->getAttributes(MapValues::class)[0] ?? null;
            $type = $map?->getArguments()[0] ?? $map?->getArguments()['type'] ?? null;

            if ($map !== null && $type !== null && array_filter($value, 'is_array') === []) {
                continue;
            }

            $uncovered[] = JsonPointer::from($here);
        }
    }

    /**
     * @param  class-string  $class
     * @param  list<string>  $path
     * @param  list<string>  $drafts
     * @param  array<class-string, true>  $seen
     */
    private static function draftsIn(string $class, array $path, array &$drafts, array $seen): void
    {
        if (isset($seen[$class])) {
            return;
        }

        $seen[$class] = true;

        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $here = [...$path, $property->getName()];

            if ($property->getAttributes(ConfigDraft::class) !== [] || $property->getAttributes(Description::class) === []) {
                $drafts[] = JsonPointer::from($here);
            }

            if (($nested = self::dataClass($property)) !== null) {
                self::draftsIn($nested, $here, $drafts, $seen);
            } elseif (($entry = self::mapEntry($property)) !== null) {
                self::draftsIn($entry, [...$here, '*'], $drafts, $seen);
            }
        }
    }

    /**
     * The Data class a property holds: its own named type, or the ONE Data member of a union whose other members are only
     * `Optional` and/or null (the generator types a map-entry key some entries lack as `Nested|Optional`; build-qa and
     * review-r1, 2026-11-16). A union of two Data classes is ambiguous and stays unresolved. The type is read by
     * {@see ConfigDataTypes}, the one reading `ConfigSection::keyDeclaration()` shares (config-sections 20).
     *
     * @return class-string<Data>|null
     */
    private static function dataClass(ReflectionProperty $property): ?string
    {
        return ConfigDataTypes::single($property);
    }

    /** @return class-string<Data>|null the entry class a `#[MapValues(Entry::class)]` names */
    private static function mapEntry(ReflectionProperty $property): ?string
    {
        return ConfigDataTypes::mapEntry($property);
    }
}
