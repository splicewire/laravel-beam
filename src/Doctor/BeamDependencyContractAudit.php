<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\Finding;

/**
 * The moat-free, generic subset of the satellite's dependency-contract audit. Given
 * parsed composer.json / composer.lock arrays, it proves the committed contract will
 * install on a clean box: no `type: path` sources in the lock, no `type: path`
 * repositories committed to composer.json, and (advisory) stability flags present when
 * dev-main pins exist.
 *
 * This is THE hard gate: the path-free/repo/stability findings can fail the doctor's exit code.
 * It also carries the generic install-contract checks relocated from the satellite doctor —
 * the marquee deploy-dark launch gate (declared) and the first-party repository closure — since
 * both are beam-shell concerns generic across sites (ADR-0082 / ADR-0095). It carries NO
 * house/fleet specifics (npm scope skew, house skills source, capability packages) — those stay
 * on the satellite/house doctor.
 */
class BeamDependencyContractAudit
{
    /**
     * First-party composer vendors (ADR-0073 + ADR-0077): schemastud/ owns the substrate,
     * splicewire/ the product, rushing/ the portfolio shelf. Order does not matter — a name
     * matches on the first vendor it prefixes.
     */
    public const FIRST_PARTY_VENDORS = ['schemastud/', 'splicewire/', 'rushing/'];

    /**
     * @param  array<string, mixed>  $composerJson
     * @param  array<string, mixed>|null  $composerLock
     * @param  list<string>  $vendors  Allowed first-party composer vendors (defaults to FIRST_PARTY_VENDORS).
     * @return list<Finding>
     */
    public function run(array $composerJson, ?array $composerLock, array $vendors = self::FIRST_PARTY_VENDORS): array
    {
        return [
            $this->lockHasNoPathRefs($composerLock),
            $this->committedReposAreGitResolved($composerJson),
            $this->stabilityConfigured($composerJson),
            $this->marqueeGateDeclared($composerJson),
            $this->firstPartyClosureDeclared($composerJson, $vendors),
        ];
    }

    /**
     * A site cannot deploy dark (public sees the `soon` splash from first DNS resolve) and flip
     * live without a redeploy unless it ships the marquee site-mode gate. Required + git-declared
     * is the deploy-critical contract; missing the require is only advisory (a site may opt out),
     * but a required-yet-undeclared marquee will not install on a clean box.
     *
     * @param  array<string, mixed>  $composerJson
     */
    private function marqueeGateDeclared(array $composerJson): Finding
    {
        $check = 'marquee site-mode gate wired';

        if (! array_key_exists('rushing/laravel-marquee', $composerJson['require'] ?? [])) {
            return Finding::warn(
                $check,
                'rushing/laravel-marquee is not required — this site cannot deploy in `soon` and flip live with `php artisan marquee:mode live`.'
            );
        }

        $repoUrls = '';
        foreach ($composerJson['repositories'] ?? [] as $repo) {
            if (is_array($repo)) {
                $repoUrls .= ' '.($repo['url'] ?? '');
            }
        }

        if (! str_contains($repoUrls, 'laravel-marquee')) {
            return Finding::fail(
                $check,
                'rushing/laravel-marquee is required but has no git repository entry — it will not install on a clean box.'
            );
        }

        return Finding::pass($check, 'marquee gate required and git-declared — deploy-dark launch flow available.');
    }

    /**
     * Composer `repositories` are root-only — a dependency's own repositories block is ignored, so
     * the app must declare a repository for every first-party package in its FULL transitive
     * closure (including ones it never names directly). A required first-party package with no
     * matching repository entry is the classic "won't resolve on a clean box" gap. Advisory (warn).
     *
     * @param  array<string, mixed>  $composerJson
     * @param  list<string>  $vendors
     */
    private function firstPartyClosureDeclared(array $composerJson, array $vendors): Finding
    {
        $check = 'first-party closure declared in repositories';
        $requires = array_keys(($composerJson['require'] ?? []) + ($composerJson['require-dev'] ?? []));
        $firstPartyRequires = array_values(array_filter(
            $requires,
            fn (string $name): bool => $this->firstPartyVendor($name, $vendors) !== null,
        ));

        if ($firstPartyRequires === []) {
            return Finding::pass($check, 'No first-party requirements to declare.');
        }

        $repoUrls = '';
        foreach ($composerJson['repositories'] ?? [] as $repo) {
            if (is_array($repo)) {
                $repoUrls .= ' '.($repo['url'] ?? '');
            }
        }

        $undeclared = [];
        foreach ($firstPartyRequires as $name) {
            $short = substr($name, strlen((string) $this->firstPartyVendor($name, $vendors)));
            if (! str_contains($repoUrls, $short)) {
                $undeclared[] = $name;
            }
        }

        if ($undeclared !== []) {
            return Finding::warn(
                $check,
                'Required first-party packages without a matching repository entry (repositories are root-only — declare the whole closure): '
                .implode(', ', $undeclared).'.'
            );
        }

        return Finding::pass($check, 'Every required first-party package has a repository entry.');
    }

    /**
     * @param  list<string>  $vendors
     */
    private function firstPartyVendor(string $name, array $vendors): ?string
    {
        foreach ($vendors as $vendor) {
            if (str_starts_with($name, $vendor)) {
                return $vendor;
            }
        }

        return null;
    }

    /**
     * THE release blocker: a `"type": "path"` source in the committed lock will not
     * install on a box without that path. The overlay is opt-in and gitignored; the
     * committed lock must always be regenerated with it off.
     *
     * @param  array<string, mixed>|null  $composerLock
     */
    private function lockHasNoPathRefs(?array $composerLock): Finding
    {
        $check = 'lock path-free';

        if ($composerLock === null) {
            return Finding::warn($check, 'No composer.lock found — run `composer update` and commit the lock.');
        }

        $pathPackages = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($composerLock[$section] ?? [] as $package) {
                $name = $package['name'] ?? '(unknown)';
                $sources = [$package['dist']['type'] ?? null, $package['source']['type'] ?? null];

                if (in_array('path', $sources, true)) {
                    $pathPackages[] = $name;
                }
            }
        }

        if ($pathPackages !== []) {
            return Finding::fail(
                $check,
                'These packages resolve via a local path and will not install on a deploy box: '
                .implode(', ', $pathPackages).'. Run `composer update` with the local overlay OFF and recommit the lock.'
            );
        }

        return Finding::pass($check, 'No path sources in the committed lock.');
    }

    /**
     * The committed `repositories` must not carry `type: path` entries — those belong
     * only in the gitignored `composer.local.json` overlay. A committed path repo
     * re-pollutes the lock on the next install.
     *
     * @param  array<string, mixed>  $composerJson
     */
    private function committedReposAreGitResolved(array $composerJson): Finding
    {
        $check = 'repos git-resolved';

        $pathRepos = [];

        foreach ($composerJson['repositories'] ?? [] as $repo) {
            if (! is_array($repo)) {
                continue;
            }

            if (($repo['type'] ?? null) === 'path') {
                $pathRepos[] = $repo['url'] ?? '(no url)';
            }
        }

        if ($pathRepos !== []) {
            return Finding::fail(
                $check,
                'Committed composer.json declares path repositories (move these to a gitignored composer.local.json overlay): '
                .implode(', ', $pathRepos).'.'
            );
        }

        return Finding::pass($check, 'All committed repositories resolve from git (or none are declared).');
    }

    /**
     * dev-main pins need `minimum-stability: dev` + `prefer-stable: true` to resolve
     * while the shared packages are unreleased. Advisory — only warns when a dev-main
     * pin is actually present but the flags are missing.
     *
     * @param  array<string, mixed>  $composerJson
     */
    private function stabilityConfigured(array $composerJson): Finding
    {
        $check = 'stability configured';

        $requires = ($composerJson['require'] ?? []) + ($composerJson['require-dev'] ?? []);
        $hasDevMainPin = false;
        foreach ($requires as $constraint) {
            if (is_string($constraint) && str_contains($constraint, 'dev-main')) {
                $hasDevMainPin = true;
                break;
            }
        }

        if (! $hasDevMainPin) {
            return Finding::pass($check, 'No dev-main pins — stability flags not required.');
        }

        $missing = [];
        if (($composerJson['minimum-stability'] ?? null) !== 'dev') {
            $missing[] = '"minimum-stability": "dev"';
        }
        if (($composerJson['prefer-stable'] ?? null) !== true) {
            $missing[] = '"prefer-stable": true';
        }

        if ($missing !== []) {
            return Finding::warn($check, 'dev-main pins present but missing: '.implode(' and ', $missing).'.');
        }

        return Finding::pass($check, 'minimum-stability=dev and prefer-stable=true.');
    }
}
