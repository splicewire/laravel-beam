<?php

namespace Splicewire\Beam\Config;

use Splicewire\Beam\Enums\ConfigTier;

/**
 * The Override Ladder as a pure fold (ADR-0245), in the style of tower's `SourceWeights`: tiers in, effective
 * values and per-key provenance out. No container, no `config()`, no file reads; {@see ConfigSectionReader} hands
 * it the tiers.
 *
 * ## The tiers it folds today
 *
 * - **Package**: the package's own config file, required in isolation.
 * - **Host**: the host's config file. Laravel's `mergeConfigFrom()` merges ONLY the top level
 *   (`array_merge($package, $host)`), so a top-level key the host's file declares replaces the package's whole
 *   value for that key. Every leaf under it is the host's, and a package leaf it hides is reported in
 *   {@see ConfigResolution::$shadowedPackageKeys}: "host shadows package".
 * - **Provider**: what `config()` actually holds when it differs from the two files. A service provider can write
 *   config at boot; the ladder names that rather than attributing the value to a file that does not hold it.
 *
 * The observed values are the truth the fold is checked against: {@see ConfigResolution::$values} is always the
 * observed array, so an effective value shown to an operator is never a reconstruction.
 *
 * Operator and tenant overrides join as sparse pointer layers above Provider (tickets 05 and 07), the tenant's on top.
 */
class ConfigLadder
{
    /**
     * @param  array<array-key, mixed>|null  $package  the package file's array, or null when the root has no package file
     * @param  list<string>|null  $hostKeys  the top-level keys the host file declares, or null when the root has no host file
     * @param  array<array-key, mixed>  $observed  `config($root)` as the running application holds it
     * @param  list<string>  $envBound  pointers an `env()` call feeds in the package file. Their re-required package
     *                                  value is only as good as the environment at read time (under `config:cache`
     *                                  `.env` is not loaded), so a difference there is the env's, not a provider's.
     * @param  array<string, ConfigLayerValue>  $operator  the operator tier's published layer, pointer => its layer
     *                                                     (ticket 05). `$observed` is then the configuration WITHOUT
     *                                                     the operator tier (the overlay's base), so an override is
     *                                                     never mistaken for a provider write.
     * @param  array<string, ConfigLayerValue>  $tenant  the current tenant's published layer, pointer => its layer
     *                                                   (ticket 07), folded above the operator tier. `$observed` stays
     *                                                   the configuration without either override tier.
     */
    public static function fold(
        ?array $package,
        ?array $hostKeys,
        array $observed,
        ?string $packageSource = null,
        ?string $hostSource = null,
        array $envBound = [],
        array $operator = [],
        array $tenant = [],
    ): ConfigResolution {
        $package ??= [];

        // The host layer's VALUES are the observed ones for the keys its file declares: the file's own values
        // would need re-evaluating its env() calls, which under `config:cache` no longer read .env. A consequence
        // stated rather than hidden: a provider that rewrites a key the host file also declares reads as the host.
        $hostLayer = array_intersect_key($observed, array_flip(array_map('strval', $hostKeys ?? [])));

        $folded = array_merge($package, $hostLayer);

        $pointers = array_values(array_unique([
            ...JsonPointer::leaves($folded),
            ...JsonPointer::leaves($observed),
            ...self::entryPointers($operator),
            ...self::entryPointers($tenant),
        ]));

        $keys = [];

        foreach ($pointers as $pointer) {
            $top = JsonPointer::segments($pointer)[0];
            $hostOwns = array_key_exists($top, $hostLayer);
            [$inPackage, $packageValue] = JsonPointer::get($package, $pointer);
            [$inFolded, $foldedValue] = JsonPointer::get($folded, $pointer);
            [$inObserved, $observedValue] = JsonPointer::get($observed, $pointer);

            $layers = [];

            if ($inPackage) {
                $layers[] = new ConfigLayerValue(ConfigTier::Package, $packageValue, $packageSource, shadowed: $hostOwns);
            }

            if ($hostOwns) {
                [$inHost, $hostValue] = JsonPointer::get($hostLayer, $pointer);

                if ($inHost) {
                    $layers[] = new ConfigLayerValue(ConfigTier::Host, $hostValue, $hostSource);
                }
            }

            $tier = $hostOwns ? ConfigTier::Host : ConfigTier::Package;

            $envExplains = ! $hostOwns && $inObserved && $inFolded && in_array($pointer, $envBound, true);

            if (! $envExplains && ($inObserved !== $inFolded || ($inObserved && $observedValue !== $foldedValue))) {
                $tier = ConfigTier::Provider;
                $layers[] = new ConfigLayerValue(ConfigTier::Provider, $inObserved ? $observedValue : null, null, removed: ! $inObserved);
            }

            // The operator tier sits above Provider: its published value is the key's effective value, a retained null
            // included (ADR-0245 decision 2), and it creates a pointer no tier below holds. The tenant tier sits above
            // the operator's, the same way (ticket 07). Each resolves through its NEAREST entry at or above the pointer
            // ({@see nearest()}), so a leaf beneath a published parent reads what the runtime holds (review-r1, 07; the
            // exact-pointer lookup was 05's defect too).
            $override = null;

            foreach ([$operator, $tenant] as $layer) {
                $resolved = self::nearest($layer, $pointer);

                if ($resolved !== null) {
                    $layers[] = $resolved;
                    $tier = $resolved->tier;
                    $override = $resolved;
                }
            }

            if (! $inObserved && $tier !== ConfigTier::Provider && $override === null) {
                continue;
            }

            // A key an ancestor's replace leaves out is absent at runtime: no row value, attributed to that tier.
            if ($override?->removed) {
                $keys[$pointer] = new ConfigResolvedKey(
                    pointer: $pointer,
                    value: null,
                    tier: $tier,
                    hostShadowsPackage: $hostOwns && array_key_exists($top, $package),
                    layers: $layers,
                );

                continue;
            }

            $keys[$pointer] = new ConfigResolvedKey(
                pointer: $pointer,
                value: $override !== null ? $override->value : ($inObserved ? $observedValue : null),
                tier: $tier,
                hostShadowsPackage: $hostOwns && array_key_exists($top, $package),
                layers: $layers,
            );
        }

        return new ConfigResolution(
            values: $observed,
            keys: $keys,
            shadowedPackageKeys: self::shadowed($package, $hostLayer),
        );
    }

    /**
     * One override tier's layer for `$pointer`, through its NEAREST entry at or above it (the longest entry pointer that is
     * the pointer or one of its ancestors), as the runtime applies entries in pointer order:
     * - the entry itself: its layer as given;
     * - a descendant in the entry's inherit list (a host-owned key, a split secret, a lock or ceiling cut): null, so the
     *   key falls through to the tier below with its true provenance;
     * - a descendant in the entry's OWNED value: that value, attributed to the entry's tier and source;
     * - a descendant in neither: the entry's replace leaves it out, so it is ABSENT, a `removed` layer of the entry's tier.
     *
     * @param  array<string, ConfigLayerValue>  $layer
     */
    private static function nearest(array $layer, string $pointer): ?ConfigLayerValue
    {
        if (isset($layer[$pointer])) {
            return $layer[$pointer];
        }

        $nearest = null;

        foreach (array_keys($layer) as $at) {
            $at = (string) $at;

            if (str_starts_with($pointer, $at.'/') && ($nearest === null || strlen($at) > strlen($nearest))) {
                $nearest = $at;
            }
        }

        if ($nearest === null) {
            return null;
        }

        $entry = $layer[$nearest];
        $relative = substr($pointer, strlen($nearest));

        foreach ($entry->inherit as $inherited) {
            if ($relative === $inherited || str_starts_with($relative, $inherited.'/')) {
                return null;
            }
        }

        $owned = $entry->ownedValue();
        [$exists, $value] = is_array($owned) ? JsonPointer::get($owned, $relative) : [false, null];

        return $exists
            ? new ConfigLayerValue($entry->tier, $value, $entry->source)
            : new ConfigLayerValue($entry->tier, null, $entry->source, removed: true);
    }

    /**
     * Every pointer a tier's entries name: each entry's own, and each leaf its owned value holds (a key that exists only
     * inside an override gets a row).
     *
     * @param  array<string, ConfigLayerValue>  $layer
     * @return list<string>
     */
    private static function entryPointers(array $layer): array
    {
        $pointers = [];

        foreach ($layer as $at => $entry) {
            $pointers[] = (string) $at;
            $owned = $entry->ownedValue();

            if (is_array($owned) && $owned !== []) {
                foreach (JsonPointer::leaves($owned) as $leaf) {
                    $pointers[] = $at.$leaf;
                }
            }
        }

        return $pointers;
    }

    /**
     * Package leaves that never reach `config()` because the host's top-level copy of their key hides them.
     *
     * @param  array<array-key, mixed>  $package
     * @param  array<array-key, mixed>  $hostLayer
     * @return list<string>
     */
    private static function shadowed(array $package, array $hostLayer): array
    {
        $shadowed = [];

        foreach (array_intersect_key($package, $hostLayer) as $top => $value) {
            foreach (JsonPointer::leaves([$top => $value]) as $pointer) {
                if (! JsonPointer::get($hostLayer, $pointer)[0]) {
                    $shadowed[] = $pointer;
                }
            }
        }

        return $shadowed;
    }
}
