<?php

namespace Splicewire\Beam\Config;

/**
 * RFC 6901 pointers into a config root. Config keys are addressed by pointer, never by dot path, because keys
 * contain dots: `app.meters` is keyed `google.cse.query` (config-sections ticket 01, finding 6).
 */
class JsonPointer
{
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
