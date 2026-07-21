<?php

namespace Splicewire\Beam\Doctor;

use Schemastud\Doctor\Finding;

/**
 * The moat-free, generic subset of the satellite's dependency-contract audit. Given
 * parsed composer.json / composer.lock arrays, it proves the committed contract will
 * install on a clean box: no `type: path` sources in the lock, no `type: path`
 * repositories committed to composer.json, and (advisory) stability flags present when
 * dev-main pins exist.
 *
 * This is THE hard gate: only these findings can fail the doctor's exit code. It carries
 * none of the satellite-specific checks (marquee, house skills, first-party closure) —
 * base beam is a substrate, not a product deployment (ADR-0082 / ADR-0095).
 */
class BeamDependencyContractAudit
{
    /**
     * @param  array<string, mixed>  $composerJson
     * @param  array<string, mixed>|null  $composerLock
     * @return list<Finding>
     */
    public function run(array $composerJson, ?array $composerLock): array
    {
        return [
            $this->lockHasNoPathRefs($composerLock),
            $this->committedReposAreGitResolved($composerJson),
            $this->stabilityConfigured($composerJson),
        ];
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
