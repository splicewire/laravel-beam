<?php

namespace Splicewire\Beam\Surgeon;

/**
 * Where the family's own PHP source lives at THIS host, read from composer's installed manifest.
 *
 * Extracted from {@see MorphAliasCoverageAudit}, which grew it first and was its only caller until
 * {@see MorphTokenBypassAudit} needed the identical reach. Copying it would have been a second
 * definition of "what counts as family source" — the fork shape this estate keeps paying for — so it
 * moved here and both audits read one answer.
 *
 * ## Honesty about reach — inherited from the original and worth restating
 * Discovery walks `vendor/composer/installed.json`, so this is WHAT THE HOST COMPOSES, not the fleet. A
 * package not installed here contributes nothing here and must be audited where it IS installed. That
 * makes every finding built on this a host fact, which is why the audits over it are advisory rather
 * than gates.
 *
 * ⚠️ `installed.json` is a SNAPSHOT taken at install time, not a live read of `vendor/`. A package
 * whose `autoload` block changed since the last `composer install` is described here by the old block.
 */
class FamilyPackageSource
{
    protected const FAMILY_VENDORS = ['splicewire/', 'rushing/', 'schemastud/'];

    /**
     * Family package name => its source dirs.
     *
     * @return array<string, list<string>>
     */
    public function dirs(): array
    {
        $manifest = base_path('vendor/composer/installed.json');

        if (! is_file($manifest)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($manifest), true);

        // Composer 2 nests under `packages`; Composer 1 was a bare list. Handle both rather than
        // silently returning nothing on the older shape.
        $packages = $decoded['packages'] ?? $decoded ?? [];

        if (! is_array($packages)) {
            return [];
        }

        $vendorDir = base_path('vendor');
        $out = [];

        foreach ($packages as $package) {
            $name = $package['name'] ?? null;

            if (! is_string($name) || ! $this->isFamily($name)) {
                continue;
            }

            $dirs = [];

            // PSR-4 roots are where a package's classes actually live; a package may declare several.
            // Only the `autoload` block — never `autoload-dev`, whose roots are fixtures and stubs.
            foreach (($package['autoload']['psr-4'] ?? []) as $paths) {
                foreach ((array) $paths as $relative) {
                    $relative = trim($relative, '/');

                    // A test/fixture root that leaked into `autoload` proper (several family packages
                    // map one). A test double is never live code, so a finding against one is pure
                    // noise — and its parents are dev-only deps that may not even be installed.
                    if (preg_match('#(^|/)(tests?|fixtures?|stubs?|database)(/|$)#i', $relative)) {
                        continue;
                    }

                    $dirs[] = $vendorDir.'/'.$name.'/'.$relative;
                }
            }

            if ($dirs !== []) {
                $out[$name] = $dirs;
            }
        }

        return $out;
    }

    public function isFamily(string $package): bool
    {
        foreach (static::FAMILY_VENDORS as $vendor) {
            if (str_starts_with($package, $vendor)) {
                return true;
            }
        }

        return false;
    }
}
