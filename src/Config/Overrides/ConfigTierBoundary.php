<?php

namespace Splicewire\Beam\Config\Overrides;

use Splicewire\Beam\Config\JsonPointer;
use Splicewire\Beam\Config\RegisteredConfigSection;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Where a tier's ownership of an override value stops: the one walk both the write side
 * ({@see ConfigOverrideStore::normalize()}) and the tenant tier's runtime and row ({@see ConfigTenantOverlay::restrict()})
 * use, so the leaves a write inherits and the leaves a hop cuts agree by construction (review-r1, 07: a nearest-declaration
 * ownership boundary).
 *
 * A key is the tier's when its NEAREST declaration's ceiling admits the tier, the key is not declared closed to it
 * (`#[ConfigKey(closed: [...])]`, config-sections 17), and no operator lock covers it (the tenant tier's locks; the
 * operator tier passes none).
 */
final class ConfigTierBoundary
{
    /**
     * @param  list<string>  $segments  the key's full path below the section root
     * @param  array<string, ConfigOverrideLock>  $locks
     */
    public static function admits(RegisteredConfigSection $registered, array $segments, ConfigTier $tier, array $locks = []): bool
    {
        // The ceiling admits the tier and the key is not closed to it (config-sections 17).
        if (! $registered->section::keyDeclaration($segments)->opensTo($tier)) {
            return false;
        }

        $pointer = JsonPointer::from($segments);

        foreach ($locks as $lock) {
            if ($lock->covers($pointer)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether any leaf of `$value`, at `$segments`, is the tier's by its nearest declaration.
     *
     * @param  list<string>  $segments
     * @param  array<array-key, mixed>  $value
     * @param  array<string, ConfigOverrideLock>  $locks
     */
    public static function admitsBelow(RegisteredConfigSection $registered, array $segments, array $value, ConfigTier $tier, array $locks = []): bool
    {
        foreach (JsonPointer::leaves($value) as $leaf) {
            if (self::admits($registered, [...$segments, ...JsonPointer::segments($leaf)], $tier, $locks)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Take every key declared CLOSED to the tier (config-sections 17) out of an object value, at the HIGHEST such pointer
     * (closure is sticky, so everything below it is closed too). Unlike {@see split()} it leaves a key above its ceiling
     * alone: this is the apply-side filter for rows stored before a package closed a key, not the write boundary. Records
     * are walked into; a list is one value.
     *
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $path  the walk's position below `$pointer`
     * @return array{0: array<array-key, mixed>, 1: list<string>} the value without them, and their pointers relative to `$pointer`
     */
    public static function closedOut(RegisteredConfigSection $registered, string $pointer, array $value, ConfigTier $tier, array $path = []): array
    {
        $base = JsonPointer::segments($pointer);
        $cut = [];

        foreach (array_keys($value) as $key) {
            $here = [...$path, (string) $key];

            if ($registered->section::keyDeclaration([...$base, ...$here])->closedTo($tier)) {
                $cut[] = JsonPointer::from($here);
                unset($value[$key]);

                continue;
            }

            if (is_array($value[$key]) && $value[$key] !== [] && ! array_is_list($value[$key])) {
                [$value[$key], $below] = self::closedOut($registered, $pointer, $value[$key], $tier, $here);
                array_push($cut, ...$below);
            }
        }

        return [$value, $cut];
    }

    /**
     * Take every key the tier may not write out of an object value, at the HIGHEST such pointer: the whole key, not its
     * leaves, so a host-owned map keeps every entry the host adds later (build-qa probe 3, 2026-11-16), and what is taken
     * out is INHERITED from the tier below instead of pinning today's value (build-qa probe 2). Keys the tier may write
     * are walked into, so a refused key nested inside an owned object is cut at its own pointer and nothing above it. A
     * refused RECORD holding a child the tier may write (nearest declaration wins, as in keyDeclaration) is walked into as
     * well, so that child stays the tier's and only the refused leaves beside it are cut (build-qa probe 4; review-r1, 07).
     * A host-owned map's entries resolve to the map's own declaration, so the map is still cut whole.
     *
     * @param  array<array-key, mixed>  $value
     * @param  array<string, ConfigOverrideLock>  $locks
     * @param  list<string>  $path  the walk's position below `$pointer`
     * @return array{0: array<array-key, mixed>, 1: list<string>} the value without them, and their pointers relative to `$pointer`
     */
    public static function split(RegisteredConfigSection $registered, string $pointer, array $value, ConfigTier $tier, array $locks = [], array $path = []): array
    {
        $base = JsonPointer::segments($pointer);
        $cut = [];

        foreach (array_keys($value) as $key) {
            $here = [...$path, (string) $key];
            $record = is_array($value[$key]) && $value[$key] !== [] && ! array_is_list($value[$key]);

            if (! self::admits($registered, [...$base, ...$here], $tier, $locks)
                && ! ($record && self::admitsBelow($registered, [...$base, ...$here], $value[$key], $tier, $locks))) {
                $cut[] = JsonPointer::from($here);
                unset($value[$key]);

                continue;
            }

            if ($record) {
                [$value[$key], $below] = self::split($registered, $pointer, $value[$key], $tier, $locks, $here);
                array_push($cut, ...$below);
            }
        }

        return [$value, $cut];
    }
}
