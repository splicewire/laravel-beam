<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Rushing\Doctor\AuditError;
use Rushing\Doctor\Concerns\RunsDoctorFloor;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\DoctorRunner;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\BeamDependencyContractAudit;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\BeamManifestAudit;
use Splicewire\Beam\Doctor\FrameManifestAudit;
use Splicewire\Beam\Doctor\IntakeDoorAudit;
use Splicewire\Beam\Doctor\MarqueeGateAudit;
use Splicewire\Beam\Doctor\McpIsolationAudit;
use Splicewire\Beam\Doctor\SchemaRoundTripAudit;
use Splicewire\Beam\Doctor\SitemapReadinessAudit;
use Splicewire\Beam\Doctor\SurgeonWiringAudit;
use Throwable;

/**
 * `php artisan splicewire:beam:doctor` — base-tier Beam readiness. Moat-free: it never requires
 * splicewire/laravel-satellite, and carries no house/fleet specifics (npm scope skew, house skills
 * source, capability packages — those stay on the satellite/house doctor).
 *
 * Owns the base/shell conformance the satellite doctor used to carry (relocated here — a satellite
 * is a beam site *plus* the moat, so beam owns the base): the dependency contract (incl. the
 * marquee deploy-dark launch gate + first-party repository closure), the BEAM.md self-description
 * manifest, the marquee runtime wiring, and Playwright MCP isolation. The frame / intake-door /
 * data-schemas checks remain advisory + presence-conditional (a headless beam app with no editor
 * rung is a valid, green configuration — ADR-0082 / ADR-0095). {@see SurgeonWiringAudit} is the
 * absence-half of beam's surgeon integration (beam-surgeon-rollout #01): it reads the host's own
 * composer.json (not runtime interface presence) so it fires even where `rushing/laravel-surgeon`
 * happens to autoload but the host never actually required it — the case `registerSurgeonAudits()`'s
 * `interface_exists` guard can never catch on its own, since a silently-inert audit has nothing to
 * report.
 *
 * Gate vs advisory: the dependency contract, the BEAM.md manifest, the marquee runtime gate, and
 * MCP isolation can fail the exit code; sitemap readiness + frame + the intake door render Pass/Warn
 * but never turn the run red. The door is advisory on the estate's precedent (1 of ~30 audits gates)
 * and because it governs an opt-in feature: a misconfigured door is a 404, not an unbootable app.
 *
 * Aggregation (beam-ux-prototype-extract ticket 08): after its own hardcoded audits, this command
 * hands the {@see BeamDoctorManifest} — the consumer tail every beam-* package self-registers its
 * {@see DoctorAudit} into (order-ascending, each carrying its own gate/advisory flag) — to the shared
 * {@see DoctorRunner} (particle-doctrine-followups ticket 05). One run aggregates the whole family;
 * beam-core's own audits stay hardcoded (coexist, not migrate) because seven of nine return a bare
 * finding rather than a list and take constructor-external inputs the container cannot supply.
 *
 * Because that head sits OUTSIDE the runner, the runner's own guard (beam-facade ticket 72) never
 * reached it, and the head runs FIRST — so one throwing core audit took down the guarded consumer
 * tail as well as itself, at the base-tier command every host runs and the satellite composes. Each
 * head audit therefore runs through {@see self::guarded()}, which produces the same
 * {@see AuditError} finding the runner would: Fail on the gate head, Warn on the advisory head, the
 * audit named, the rest of the run completing (beam-facade ticket 90).
 *
 * `--floor` sets the severity a GATE registration's finding must reach to fail the run (default
 * `fail`). A repo mid-migration runs at the default; a converged one runs `--floor=warn` so a
 * warning gate-fails. The floor governs the runner-owned consumer tail; the hardcoded core audits
 * keep their fixed Fail gate (they sit outside the runner by the same coexist-not-migrate policy).
 * Advisory findings render but never fail the exit code, at any floor.
 *
 * Output format (the parse target for a future <DoctorOutput>): each finding renders as one line
 * `<check>: <detail>` at info (Pass) / warn (Warn) / error (Fail).
 */
class BeamDoctorCommand extends Command
{
    use RunsDoctorFloor;

    protected $signature = 'splicewire:beam:doctor
        {--floor=fail : Severity a gate finding must reach to fail the run (pass|warn|fail)}';

    protected $description = 'Base-tier Beam readiness (moat-free; owns the base/shell conformance: dependency contract, BEAM.md manifest, marquee gate, MCP isolation).';

    public function handle(BeamDoctorManifest $manifest, DoctorRunner $runner): int
    {
        $floor = $this->parseFloor();

        if ($floor === null) {
            return self::FAILURE;
        }

        $base = $this->laravel->basePath();

        // Pre-audit bail, deliberately outside the guard as well as outside the runner (ticket 90
        // §3, re-affirmed): this is not a throw and must not become an `audit-errored` finding.
        // Without a readable composer.json there is no host to audit and nothing to hand the two
        // audits that take it as an argument — the run is refused, not degraded. `audit-errored`
        // says "one check could not look"; this says "there is nothing here to look at".
        $composerJson = $this->readJson($base.'/composer.json');

        if ($composerJson === null) {
            $this->components->error('No readable composer.json at '.$base.'.');

            return self::FAILURE;
        }

        // Gate findings — these can fail the exit code (a beam site is not installable/deployable
        // clean without them). Every one goes through `guarded()`: the head runs BEFORE the runner,
        // so an unguarded throw here took the guarded consumer tail down with it (ticket 90).
        $gateFindings = array_merge(
            $this->guarded(
                BeamDependencyContractAudit::class,
                true,
                fn (BeamDependencyContractAudit $audit) => $audit->run($composerJson, $this->readJson($base.'/composer.lock')),
            ),
            $this->guarded(
                BeamManifestAudit::class,
                true,
                fn (BeamManifestAudit $audit) => $this->manifestFinding($audit, $base),
            ),
            $this->guarded(
                MarqueeGateAudit::class,
                true,
                fn (MarqueeGateAudit $audit) => $this->marqueeGateFinding($audit),
            ),
            $this->guarded(
                McpIsolationAudit::class,
                true,
                fn (McpIsolationAudit $audit) => $this->mcpIsolationFinding($audit, $base),
            ),
        );

        // Advisory, presence-conditional or report-only — never fail the exit code. The intake door is
        // the one that returns a LIST: it reports per-slug, because a host declaring four intake slugs
        // with two of them unresolvable wants both named, not the first one and a count.
        $advisoryFindings = array_merge(
            $this->guarded(
                SchemaRoundTripAudit::class,
                false,
                fn (SchemaRoundTripAudit $audit) => $audit->run(),
            ),
            $this->guarded(
                FrameManifestAudit::class,
                false,
                fn (FrameManifestAudit $audit) => $audit->run(),
            ),
            $this->guarded(
                IntakeDoorAudit::class,
                false,
                fn (IntakeDoorAudit $audit) => $audit->run(),
            ),
            $this->guarded(
                SitemapReadinessAudit::class,
                false,
                fn (SitemapReadinessAudit $audit) => $this->sitemapFinding($audit, $base),
            ),
            $this->guarded(
                SurgeonWiringAudit::class,
                false,
                fn (SurgeonWiringAudit $audit) => $audit->run($composerJson),
            ),
        );

        $gateFailed = false;

        foreach ($gateFindings as $finding) {
            $this->renderFinding($finding);
            $gateFailed = $gateFailed || $finding->status === DoctorStatus::Fail;
        }

        $this->renderFindings($advisoryFindings);

        // Consumer tail: every beam-* package that self-registered a DoctorAudit into the manifest
        // (order-ascending), executed by the shared runner. A gate registration's finding at or above
        // the floor joins the exit code; an advisory one only renders. The runner collects every
        // finding before throwing, so a failure is still a full work-list.
        [$report, $runnerFailed] = $this->runAtFloor($runner, $manifest->registrations(), $floor);
        $gateFailed = $gateFailed || $runnerFailed;

        $this->renderFindings($report->findings);

        if ($gateFailed) {
            $this->newLine();
            $this->components->error('Base beam conformance has blocking failures — this beam app will not install/deploy clean.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Run one hardcoded head audit the way {@see DoctorRunner::collect()} runs a registered one:
     * resolve it, run it, and turn either failure into the fleet's {@see AuditError} finding rather
     * than a crash. Both phases are separate because they fail for different reasons and the
     * check-strings already tell them apart (`audit-errored.resolve` vs `audit-errored`).
     *
     * **Why a wrapper and not a fold into the runner (ticket 90 §1).** Seven of these nine audits
     * take their inputs on `run()` — the parsed composer.json, the lock, the front matter, the web
     * middleware group — which {@see DoctorAudit}'s argument-free contract cannot carry, and that is
     * the whole reason they stayed hardcoded (coexist, not migrate — see {@see BeamDoctorManifest}).
     * Folding them in means an adapter or a closure registration per audit whose only purpose is to
     * reach a `try`. The wrapper buys the same protection at the cost of one call, and leaves the
     * coexist decision exactly where it was.
     *
     * **Why the audit is named explicitly (ticket 90 §2).** A closure has no class-string, and
     * {@see AuditError} takes one so the operator can tell WHICH check died. The alternative was to
     * grow the shared factory a caller-supplied label — widening a fleet surface for one caller —
     * so the call site passes its class instead. Resolving through the container rather than `new`
     * is what makes that class-string honest (it is the thing that was actually resolved) and is
     * also the seam the tests bind a throwing double into; these audits have no bindings, so at a
     * host `make()` is `new`.
     *
     * @param  class-string  $audit
     * @param  callable(object): (Finding|list<Finding>)  $run
     * @return list<Finding>
     */
    private function guarded(string $audit, bool $gate, callable $run): array
    {
        try {
            $instance = $this->laravel->make($audit);
        } catch (Throwable $e) {
            return [AuditError::resolving($audit, $gate, $e)];
        }

        try {
            $findings = $run($instance);
        } catch (Throwable $e) {
            return [AuditError::running($audit, $gate, $e)];
        }

        return $findings instanceof Finding ? [$findings] : array_values($findings);
    }

    /**
     * The site is self-describing (ADR-0001): a valid BEAM.md must exist and declare its identity
     * (legacy SATELLITE.md still accepted during the rename). Reads + parses the front matter and
     * hands the parsed identity to the pure audit.
     */
    private function manifestFinding(BeamManifestAudit $audit, string $base): Finding
    {
        $path = $this->manifestPath($base);
        $exists = is_file($path);
        $frontMatter = $exists ? $this->frontMatter((string) file_get_contents($path)) : [];

        return $audit->run(
            $exists,
            $frontMatter['satellite'] ?? null,
            $frontMatter['variant'] ?? null,
        );
    }

    /**
     * Runtime check: is the site-mode gate actually in the `web` group? The dependency audit
     * proves marquee is declared; this proves it is enforced.
     */
    private function marqueeGateFinding(MarqueeGateAudit $audit): Finding
    {
        $middleware = (string) config(
            'beam.marquee.middleware',
            'Rushing\\Marquee\\Middleware\\EnforceSiteMode',
        );

        return $audit->run(
            $this->laravel['router']->getMiddlewareGroups()['web'] ?? [],
            $middleware,
            (bool) config('beam.core.marquee.auto_register', true),
            class_exists($middleware),
        );
    }

    /**
     * Collect every Playwright MCP registration — the committed `.mcp.json` and the developer's
     * global `~/.claude.json` project entry — and prove each one isolates the browser.
     */
    private function mcpIsolationFinding(McpIsolationAudit $audit, string $base): Finding
    {
        $registrations = [];

        $local = $this->readJson($base.'/.mcp.json');
        if ($command = $this->playwrightCommand($local['mcpServers']['playwright'] ?? null)) {
            $registrations[] = ['source' => '.mcp.json', 'command' => $command];
        }

        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if ($home) {
            $global = $this->readJson($home.'/.claude.json');
            $server = $global['projects'][$base]['mcpServers']['playwright'] ?? null;
            if ($command = $this->playwrightCommand($server)) {
                $registrations[] = ['source' => '~/.claude.json', 'command' => $command];
            }
        }

        return $audit->run($registrations);
    }

    /**
     * Report-only: is the sitemap enabled, and does a static public/ file shadow the mode-aware
     * routes? Reads `beam.sitemap.*`.
     */
    private function sitemapFinding(SitemapReadinessAudit $audit, string $base): Finding
    {
        $public = $base.'/public';
        $path = ltrim((string) config('beam.core.sitemap.path', 'sitemap.xml'), '/');

        $shadowing = [];
        foreach ([$path, 'robots.txt'] as $file) {
            if (is_file($public.DIRECTORY_SEPARATOR.$file)) {
                $shadowing[] = 'public/'.$file;
            }
        }

        return $audit->run(
            (bool) config('beam.core.sitemap.enabled', true),
            $shadowing,
        );
    }

    /**
     * Flatten an MCP server config into a single command string (command + args, sh -c body
     * included) so the isolation audit can scan it for `--isolated` / `--user-data-dir`.
     *
     * @param  array<string, mixed>|null  $server
     */
    private function playwrightCommand(?array $server): ?string
    {
        if ($server === null) {
            return null;
        }

        $parts = [(string) ($server['command'] ?? '')];
        foreach ((array) ($server['args'] ?? []) as $arg) {
            $parts[] = (string) $arg;
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Resolve the manifest file at $base: prefer the current BEAM.md, fall back to legacy
     * SATELLITE.md; return the BEAM.md path when neither exists so a missing-file check names the
     * current convention.
     */
    private function manifestPath(string $base): string
    {
        $current = $base.'/BEAM.md';
        if (is_file($current)) {
            return $current;
        }
        $legacy = $base.'/SATELLITE.md';

        return is_file($legacy) ? $legacy : $current;
    }

    /**
     * Minimal front-matter reader: pull the leading `---`-fenced block and its top-level scalar
     * keys. Deliberately not a general YAML parser — beam only needs `satellite` + `variant`, and
     * stays free of a YAML dependency (and of laravel-satellite's Manifest class).
     *
     * @return array<string, string>
     */
    private function frontMatter(string $contents): array
    {
        if (! preg_match('/\A\x{FEFF}?\s*---\R(.*?)\R---\s*(?:\R|\z)/us', $contents, $m)) {
            return [];
        }

        $values = [];
        foreach (preg_split('/\R/', $m[1]) as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+):\s*(\S.*?)\s*$/', $line, $kv)) {
                $values[$kv[1]] = trim($kv[2], "\"'");
            }
        }

        return $values;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
