<?php

namespace Splicewire\Beam\Config;

use InvalidArgumentException;

/**
 * RFC 6901 pointers into a config root. Config keys are addressed by pointer, never by dot path, because keys
 * contain dots: `app.meters` is keyed `google.cse.query` (config-sections ticket 01, finding 6).
 *
 * The pointer is the PUBLIC address: on the wire, in override rows, and in the ladder. A DataOverlay JSONPath
 * exists only at the one place a tier's ops are applied to a document, through {@see self::toJsonPath()}
 * (ADR-0242 decision 2).
 */
class JsonPointer
{
    /**
     * The concrete DataOverlay JSONPath for a pointer: every segment bracket-quoted, so a dotted key stays one
     * segment (`/google.cse.query/unit_cost_usd` → `$['google.cse.query']['unit_cost_usd']`).
     *
     * DataOverlay's concrete-target parser (`schemastud/laravel-data-schemas` `src/Overlay/ConcreteTarget.php`)
     * has no escape for a quote or a closing bracket inside a quoted segment, so a key holding either cannot be
     * addressed, and is refused here by name rather than turned into a path that names a different node.
     *
     * @throws InvalidArgumentException
     */
    public static function toJsonPath(string $pointer): string
    {
        $path = '$';

        foreach (self::segments($pointer) as $segment) {
            if (str_contains($segment, "'") || str_contains($segment, ']')) {
                throw new InvalidArgumentException(sprintf('Config key [%s] in [%s] holds a quote or a closing bracket, which a DataOverlay JSONPath cannot address.', $segment, $pointer));
            }

            $path .= "['{$segment}']";
        }

        return $path;
    }

    /** @param list<string|int> $segments */
    public static function from(array $segments): string
    {
        return implode('', array_map(
            fn (string|int $segment): string => '/'.str_replace(['~', '/'], ['~0', '~1'], (string) $segment),
            $segments,
        ));
    }

    /** @return list<string> */
    public static function segments(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }

        return array_map(
            fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            array_slice(explode('/', $pointer), 1),
        );
    }

    /**
     * Whether `$pointer` addresses a value inside `$values`, and that value. Literal key access, so a dotted key
     * is one segment.
     *
     * @param  array<array-key, mixed>  $values
     * @return array{0: bool, 1: mixed}
     */
    public static function get(array $values, string $pointer): array
    {
        $current = $values;

        foreach (self::segments($pointer) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return [false, null];
            }

            $current = $current[$segment];
        }

        return [true, $current];
    }

    /**
     * Every leaf pointer in `$values`, depth-first in key order. A leaf is anything but a non-empty string-keyed
     * map: a scalar, an object, an empty array, or a list (a list is one value, edited whole).
     *
     * @param  array<array-key, mixed>  $values
     * @param  list<string|int>  $prefix
     * @return list<string>
     */
    public static function leaves(array $values, array $prefix = []): array
    {
        $leaves = [];

        foreach ($values as $key => $value) {
            $path = [...$prefix, $key];

            if (self::isMap($value)) {
                array_push($leaves, ...self::leaves($value, $path));
            } else {
                $leaves[] = self::from($path);
            }
        }

        return $leaves;
    }

    public static function isMap(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }
}
