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
 *     precisely because it *works*: a docs page appears, just not the one this estate renders. Read as
 *     **effective** values against Scribe's own defaults, so a host that published nothing is reported
 *     as mounting `/docs` rather than as merely un-configured — see {@see emitterOnly()}.
 *  2. **The artifact exists.** With none, `beam/openapi.{yaml,json}` 404 — correct behaviour, and a
 *     broken OTB promise. Generation happens at install and deploy, never on request.
 *  3. **Deploy-time regeneration is wired.** A composer script invoking `scribe:generate`, so the spec
 *     tracks the routes instead of freezing at whatever the install produced.
 *  4. **The artifact describes something.** A spec with zero `paths` is the failure mode "self-documenting"
 *     degrades into, and it is invisible from every other angle: generation succeeds, the file exists, the
 *     route 200s, and Scalar renders a tidy empty page. It happens whenever the match rules describe a
 *     route layout this host does not have — which is what the original `['api/*']` default did to every
 *     bare install (ADR-0211 §7, amended). This check is deliberately about the OUTPUT rather than the
 *     rules: no prefix list can be right for every host, but "did this produce a document that describes
 *     anything" is checkable everywhere and cannot go stale.
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

    private const DESCRIBES = 'the spec describes at least one route';

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
            $this->specDescribesRoutes(),
        ];
    }

    /**
     * Check 4. Reads the artifact rather than the match rules, because the rules are host-shaped and the
     * output is not: whatever prefixes this host chose, a spec with no `paths` documents nothing.
     *
     * Parsed shallowly on purpose — a `paths:` key with at least one child, found by scanning for a
     * top-level line, not by loading a YAML parser to answer a yes/no question about a file that may be
     * megabytes. A malformed artifact reports as unreadable rather than as empty; those are different
     * problems with different fixes.
     */
    private function specDescribesRoutes(): Finding
    {
        $path = $this->specSource()->artifactPath();

        if (! is_file($path)) {
            return Finding::warn(
                self::DESCRIBES,
                'No artifact to inspect — see the artifact check above, which names the fix.',
            );
        }

        $contents = @file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            return Finding::warn(self::DESCRIBES, "The artifact at {$path} is empty or unreadable.");
        }

        if ($this->countsPaths($contents) > 0) {
            return Finding::pass(self::DESCRIBES, 'The generated spec describes at least one route.');
        }

        return Finding::warn(
            self::DESCRIBES,
            "The artifact at {$path} has no `paths` — this host generated a spec describing ZERO routes, ".
            'and beam/openapi.yaml is serving it. Nothing else reports this: generation succeeded, the file '.
            'exists, and the reference page renders an empty document. The cause is almost always '.
            '`scribe.routes.*.match.prefixes` naming a layout this host does not have — compare it against '.
            '`php artisan route:list`. A bare beam install mounts no route under `api/*`; its sockets are at '.
            'frame.route_prefix and beam.ux.api_root, which is why beam\'s stub derives the list from those '.
            'keys instead of hardcoding one.',
        );
    }

    /**
     * How many path items the spec's top-level `paths` block holds. `paths:` followed by anything that is
     * not another top-level key means at least one entry; `paths: {}` and a `paths:` with nothing indented
     * beneath it both mean none.
     */
    private function countsPaths(string $yaml): int
    {
        $lines = preg_split('/\R/', $yaml) ?: [];
        $inPaths = false;
        $count = 0;

        foreach ($lines as $line) {
            if (preg_match('/^paths:\s*(.*)$/', $line, $matches) === 1) {
                $inline = trim($matches[1]);

                if ($inline !== '' && $inline !== '{}') {
                    return 1;
                }

                $inPaths = true;

                continue;
            }

            if (! $inPaths) {
                continue;
            }

            // A non-indented, non-blank line ends the block — we are back at another top-level key.
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (! str_starts_with($line, ' ')) {
                break;
            }

            // Exactly one level of indent under `paths:` is a path item ("  /api/users:").
            if (preg_match('/^\s{1,4}\S/', $line) === 1 && str_contains($line, ':')) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Check 1, read as EFFECTIVE values rather than as written ones (beam-docs-satellite ticket 38).
     *
     * This check used to treat a missing `scribe` config as "an un-configured host, not a violation" and
     * return early. That was exactly backwards, and it was measured to be: on a fresh
     * `laravel-beam-starter` with no published stub, `GET /docs` **500s** on a missing `scribe.index`
     * blade view, and the seeded docs entry underneath it is never reached. Scribe's own unpublished
     * defaults are `type => 'laravel'`, `laravel.add_routes => TRUE`, `laravel.docs_url => '/docs'`
     * (Knuckles\Scribe\ScribeServiceProvider::bootRoutes()), so "no config" is not the absence of a
     * docs UI — it is the *worst* case of one, mounted at the exact URL beam seeds its docs subtree at.
     *
     * Hence both defaults below are **Scribe's**, not beam's. The old `config('scribe.laravel.add_routes',
     * false)` fell back to `false` where the library falls back to `true`, so the audit answered a
     * different question than the router did — the same shape as the dead-config-key sweep (a test
     * seeding the key it read) and ticket 21's "an OTB promise is only proven on a bare host".
     *
     * Still advisory, for the reason in the class docblock: a host that genuinely wants Scribe's UI is
     * making a defensible choice. The publish hint is folded into the finding rather than short-circuiting
     * it, so the message names both the live mount and the one-line fix.
     */
    private function emitterOnly(): Finding
    {
        $published = config('scribe.type') !== null;

        $type = (string) config('scribe.type', 'laravel');
        $addRoutes = (bool) config('scribe.laravel.add_routes', true);
        $docsUrl = (string) config('scribe.laravel.docs_url', '/docs');

        $problems = [];

        if ($type !== 'laravel') {
            $problems[] = "`scribe.type` is `{$type}`, not `laravel` — `static` writes an HTML docs site ".
                'into public/docs, and the `external_*` types hand the spec to a third-party UI';
        }

        if ($addRoutes && str_ends_with($type, 'laravel')) {
            $problems[] = "Scribe mounts its own HTML docs route at `{$docsUrl}` (`scribe.laravel.".
                'add_routes` is true) — a second unbranded docs UI at a URL beam does not own, which '.
                "shadows any entry seeded at `{$docsUrl}` because the entry renderer's catch-all is ".
                'mounted last by construction (ADR-0209 §2)';
        }

        if ($problems !== []) {
            $fix = $published
                ? 'Set `type` => `laravel` and `laravel.add_routes` => false in config/scribe.php.'
                : 'This host has NO config/scribe.php, so these are Scribe\'s own unpublished defaults — '.
                    'publish beam\'s stub with `php artisan vendor:publish --tag=beam-scribe` (or re-run '.
                    '`splicewire:beam:install`, which does it for you), which ships the emitter-only pair '.
                    'and the exposure boundary.';

            return Finding::warn(
                self::EMITTER,
                'Scribe is producing more than an artifact: '.implode('; ', $problems).
                '. Beam serves the spec at beam/openapi.{yaml,json} and renders it with Scalar (ADR-0028). '.
                $fix.' Advisory — a host that deliberately wants Scribe\'s own UI is free to keep this.',
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
