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
 * Operator and tenant overrides join as sparse pointer layers above Provider when the override store exists.
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
     */
    public static function fold(
        ?array $package,
        ?array $hostKeys,
        array $observed,
        ?string $packageSource = null,
        ?string $hostSource = null,
        array $envBound = [],
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

            if (! $inObserved && $tier !== ConfigTier::Provider) {
                continue;
            }

            $keys[$pointer] = new ConfigResolvedKey(
                pointer: $pointer,
                value: $inObserved ? $observedValue : null,
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
