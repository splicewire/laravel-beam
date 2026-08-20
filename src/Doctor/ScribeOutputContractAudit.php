<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\OpenApi\ConfiguredArtifactSpecSource;
use Splicewire\Beam\Surgeon\UndescribedRegistryAudit;

/**
 * Holds ADR-0028's no-HTML line, and reports whether this host actually has a spec to serve (ADR-0211 §8).
 *
 * Three checks, all ADVISORY:
 *
 *  1. **Scribe stays an emitter.** `type` must be `laravel` and `laravel.add_routes` must be false.
 *     A host that flips either silently grows a second, unbranded docs UI at a URL beam does not own —
 *     which also collides with the entry renderer's catch-all (ADR-0209). The failure mode is invisible
 *     precisely because it *works*: a docs page appears, just not the one this estate renders.
 *  2. **The artifact exists.** With none, `beam/openapi.{yaml,json}` 404 — correct behaviour, and a
 *     broken OTB promise. Generation happens at install and deploy, never on request.
 *  3. **Deploy-time regeneration is wired.** A composer script invoking `scribe:generate`, so the spec
 *     tracks the routes instead of freezing at whatever the install produced.
 *
 * ## Why none of it gates
 *
 * Beam reserves `gate: true` for "an agent is building a thing wrong" — exactly one audit today
 * ({@see UndescribedRegistryAudit}). A host that deliberately wants Scribe's static UI, or that has
 * genuinely not generated yet, is making a defensible choice to report, not to block. Every finding here
 * names the fix.
 *
 * ## Reach
 *
 * Repo-local: it reads the config and the composer manifest of the host it is booted in, and nothing
 * about the estate.
 */
class ScribeOutputContractAudit implements DoctorAudit
{
    private const EMITTER = 'Scribe emits the spec, beam renders it (no second docs UI)';

    private const ARTIFACT = 'an OpenAPI artifact exists to serve';

    private const REGENERATION = 'the spec regenerates on deploy';

    public function __construct(private ?string $basePath = null) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        return [
            $this->emitterOnly(),
            $this->artifactPresent(),
            $this->regenerationWired(),
        ];
    }

    /**
     * Check 1. A host that has never published the stub has no `scribe.type` at all — which is not a
     * violation, it is an un-configured host, and the artifact check below is the one that will speak up.
     */
    private function emitterOnly(): Finding
    {
        $type = config('scribe.type');

        if ($type === null) {
            return Finding::warn(
                self::EMITTER,
                'No `scribe` config on this host — publish beam\'s stub with '.
                '`php artisan vendor:publish --tag=beam-scribe`, which ships the emitter-only defaults '.
                'and the `api/*` exposure boundary.',
            );
        }

        $problems = [];

        if ($type !== 'laravel') {
            $problems[] = "`scribe.type` is `{$type}`, not `laravel` — `static` writes an HTML docs site ".
                'into public/docs, and the `external_*` types hand the spec to a third-party UI';
        }

        if (config('scribe.laravel.add_routes', false)) {
            $problems[] = '`scribe.laravel.add_routes` is true — Scribe mounts its own /docs route, a '.
                'second unbranded docs UI at a URL beam does not own (and one that collides with the '.
                'entry renderer\'s catch-all)';
        }

        if ($problems !== []) {
            return Finding::warn(
                self::EMITTER,
                'Scribe is producing more than an artifact: '.implode('; ', $problems).
                '. Beam serves the spec at beam/openapi.{yaml,json} and renders it with Scalar (ADR-0028). '.
                'Advisory — a host that deliberately wants Scribe\'s own UI is free to keep this.',
            );
        }

        return Finding::pass(
            self::EMITTER,
            'Scribe is emitter-only (`type` = laravel, `add_routes` = false); beam owns the docs URLs.',
        );
    }

    /** Check 2. Presence, plus how old — a spec frozen months behind the routes is its own kind of wrong. */
    private function artifactPresent(): Finding
    {
        $path = $this->specSource()->artifactPath();

        if (! is_file($path)) {
            return Finding::warn(
                self::ARTIFACT,
                "No artifact at {$path}, so beam/openapi.yaml and beam/openapi.json both 404. Run ".
                '`php artisan scribe:generate` (or re-run `splicewire:beam:install`, which does it for you).',
            );
        }

        $age = time() - (int) (filemtime($path) ?: 0);
        $days = intdiv($age, 86400);

        if ($days >= 30) {
            return Finding::warn(
                self::ARTIFACT,
                "The artifact at {$path} was generated {$days} days ago. Nothing regenerates it on ".
                'request (extraction reflects over every route, and a public GET must not write to '.
                'storage), so it describes the routes as they were then.',
            );
        }

        return Finding::pass(self::ARTIFACT, "Artifact present at {$path} ({$days}d old).");
    }

    /**
     * Check 3. The install step gives a fresh host a spec once; only a deploy hook keeps it true. Beam
     * cannot ship that script — it is a package, and `artisan` belongs to the host — so it reports the
     * absence and names the line to add.
     */
    private function regenerationWired(): Finding
    {
        $manifest = $this->manifest();

        if ($manifest === null) {
            return Finding::warn(self::REGENERATION, 'No readable composer.json at the repo root.');
        }

        $scripts = (array) ($manifest['scripts'] ?? []);
        $flat = [];

        array_walk_recursive($scripts, static function ($line) use (&$flat): void {
            $flat[] = (string) $line;
        });

        foreach ($flat as $line) {
            if (str_contains($line, 'scribe:generate')) {
                return Finding::pass(self::REGENERATION, 'A composer script runs `scribe:generate`.');
            }
        }

        return Finding::warn(
            self::REGENERATION,
            'No composer script invokes `scribe:generate`, so the artifact only ever regenerates by hand. '.
            'Add `"docs": "@php artisan scribe:generate"` to composer.json scripts and call it from your '.
            'deploy pipeline.',
        );
    }

    private function specSource(): ConfiguredArtifactSpecSource
    {
        return app(ConfiguredArtifactSpecSource::class);
    }

    /** @return array<string, mixed>|null */
    private function manifest(): ?array
    {
        $base = $this->basePath ?? base_path();
        $path = rtrim($base, '/').'/composer.json';

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
