<?php

namespace Splicewire\Beam\Config;

use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Schemastud\DataSchemas\Attributes\MapValues;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * The ONE reading of a Config Section property's type that both walks share (config-sections 20):
 * {@see ConfigSection::keyDeclaration()} (what a key declares, secrecy above all) and {@see ConfigSectionCoverage} (which
 * keys a section describes). Before it the two walks read types separately and had drifted.
 *
 * What it resolves:
 * - the Data member(s) of a named type (`Nested`), a nullable type (`?Nested`) or a union (`Nested|Optional`,
 *   `Nested|Optional|null`, `A|B`); `Optional`, null and scalars hold no keys;
 * - the Data member of an intersection (`Nested&Iface`), also inside a DNF union (`(Nested&Iface)|null`);
 * - `self` and `static` (the property's declaring class) and `parent` (that class's parent);
 * - the entry class of a MAP of records, `#[MapValues(Entry::class)] array` (the shape the generator emits for a nested map
 *   of records), and of a LIST of records, `#[DataCollectionOf(Entry::class)]` on an `array` or a `DataCollection`.
 *
 * What it does NOT resolve (no Data member is returned): an interface, an untyped property, an array with no item
 * attribute, and properties declared only on a concrete subclass of an abstract Data type (the walk reads the declared
 * class). A secret declared there is not found.
 *
 * {@see nested()} serves the declaration walk (every Data member); {@see single()} serves Coverage (exactly one Data member
 * and nothing else but `Optional`/null, so a two-Data union, a Data with a scalar and an intersection stay unresolved).
 */
final class ConfigDataTypes
{
    /**
     * Every Data class a property's type holds, ignoring members that hold no keys.
     *
     * @return list<class-string<Data>>
     */
    public static function nested(ReflectionProperty $property): array
    {
        return self::members($property)['data'];
    }

    /**
     * The ONE Data class a property holds when every other member is only `Optional` and/or null, or null when there is
     * none or more than one (Coverage's rule: a union of two Data classes, or Data with a scalar, is ambiguous).
     *
     * @return class-string<Data>|null
     */
    public static function single(ReflectionProperty $property): ?string
    {
        ['data' => $data, 'other' => $other] = self::members($property);

        return ! $other && count($data) === 1 ? $data[0] : null;
    }

    /** @return class-string<Data>|null the entry class a `#[MapValues(Entry::class)]` names */
    public static function mapEntry(ReflectionProperty $property): ?string
    {
        $map = $property->getAttributes(MapValues::class)[0] ?? null;

        return self::dataClassOrNull($map?->getArguments()[0] ?? $map?->getArguments()['type'] ?? null);
    }

    /** @return class-string<Data>|null the entry class a `#[DataCollectionOf(Entry::class)]` names */
    public static function listEntry(ReflectionProperty $property): ?string
    {
        $list = $property->getAttributes(DataCollectionOf::class)[0] ?? null;

        return self::dataClassOrNull($list?->getArguments()[0] ?? $list?->getArguments()['class'] ?? null);
    }

    /**
     * @return array{data: list<class-string<Data>>, other: bool} the Data members, and whether any member is neither
     *                                                            Data nor `Optional`/null (a scalar, an intersection, …)
     */
    private static function members(ReflectionProperty $property): array
    {
        $type = $property->getType();

        if ($type === null) {
            return ['data' => [], 'other' => true];
        }

        $data = [];
        $other = false;

        foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : [$type] as $member) {
            if ($member instanceof ReflectionIntersectionType) {
                // Flattened for the declaration walk; still "other" for Coverage, which leaves an intersection unresolved.
                $other = true;

                foreach ($member->getTypes() as $part) {
                    if ($part instanceof ReflectionNamedType && ($class = self::dataClass($property, $part)) !== null) {
                        $data[] = $class;
                    }
                }

                continue;
            }

            if (! $member instanceof ReflectionNamedType) {
                $other = true;

                continue;
            }

            $name = $member->getName();

            if (($class = self::dataClass($property, $member)) !== null) {
                $data[] = $class;
            } elseif ($name !== Optional::class && $name !== 'null') {
                $other = true;
            }
        }

        // A nullable named type (`?Nested`) is one ReflectionNamedType that allows null: nothing else to read.
        return ['data' => array_values(array_unique($data)), 'other' => $other];
    }

    /**
     * The Data class one named type member names, with `self`/`static` resolved to the property's declaring class and
     * `parent` to that class's parent; null for anything else.
     *
     * @return class-string<Data>|null
     */
    private static function dataClass(ReflectionProperty $property, ReflectionNamedType $member): ?string
    {
        if ($member->isBuiltin()) {
            return null;
        }

        $declaring = $property->getDeclaringClass();
        $name = match (strtolower($member->getName())) {
            'self', 'static' => $declaring->getName(),
            'parent' => $declaring->getParentClass() !== false ? $declaring->getParentClass()->getName() : null,
            default => $member->getName(),
        };

        return self::dataClassOrNull($name);
    }

    /** @return class-string<Data>|null */
    private static function dataClassOrNull(mixed $class): ?string
    {
        return is_string($class) && class_exists($class) && is_subclass_of($class, Data::class) ? $class : null;
    }
}
