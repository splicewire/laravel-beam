<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\BeamDependencyContractAudit;
use Splicewire\Beam\Doctor\BeamManifestAudit;
use Splicewire\Beam\Doctor\FrameManifestAudit;
use Splicewire\Beam\Doctor\MarqueeGateAudit;
use Splicewire\Beam\Doctor\McpIsolationAudit;
use Splicewire\Beam\Doctor\SchemaFormsDoorAudit;
use Splicewire\Beam\Doctor\SchemaRoundTripAudit;
use Splicewire\Beam\Doctor\SitemapReadinessAudit;

/**
 * `php artisan splicewire:beam:doctor` — base-tier Beam readiness. Moat-free: it never requires
 * splicewire/laravel-satellite, and carries no house/fleet specifics (npm scope skew, house skills
 * source, capability packages — those stay on the satellite/house doctor).
 *
 * Owns the base/shell conformance the satellite doctor used to carry (relocated here — a satellite
 * is a beam site *plus* the moat, so beam owns the base): the dependency contract (incl. the
 * marquee deploy-dark launch gate + first-party repository closure), the BEAM.md self-description
 * manifest, the marquee runtime wiring, and Playwright MCP isolation. The frame / schema-forms /
 * data-schemas checks remain advisory + presence-conditional (a headless beam app with no editor
 * rung is a valid, green configuration — ADR-0082 / ADR-0095).
 *
 * Gate vs advisory: the dependency contract, the BEAM.md manifest, the marquee runtime gate, and
 * MCP isolation can fail the exit code; sitemap readiness + frame/schema-forms render Pass/Warn but
 * never turn the run red.
 *
 * Output format (the parse target for a future <DoctorOutput>): each finding renders as one line
 * `<check>: <detail>` at info (Pass) / warn (Warn) / error (Fail).
 */
class BeamDoctorCommand extends Command
{
    protected $signature = 'splicewire:beam:doctor';

    protected $description = 'Base-tier Beam readiness (moat-free; owns the base/shell conformance: dependency contract, BEAM.md manifest, marquee gate, MCP isolation).';

    public function handle(): int
    {
        $base = $this->laravel->basePath();

        $composerJson = $this->readJson($base.'/composer.json');

        if ($composerJson === null) {
            $this->components->error('No readable composer.json at '.$base.'.');

            return self::FAILURE;
        }

        // Gate findings — these can fail the exit code (a beam site is not installable/deployable
        // clean without them).
        $gateFindings = array_merge(
            (new BeamDependencyContractAudit)->run($composerJson, $this->readJson($base.'/composer.lock')),
            [
                $this->manifestFinding($base),
                $this->marqueeGateFinding(),
                $this->mcpIsolationFinding($base),
            ],
        );

        // Advisory, presence-conditional or report-only — never fail the exit code.
        $advisoryFindings = [
            (new SchemaRoundTripAudit)->run(),
            (new FrameManifestAudit)->run(),
            (new SchemaFormsDoorAudit)->run(),
            $this->sitemapFinding($base),
        ];

        $gateFailed = false;

        foreach ($gateFindings as $finding) {
            $this->render($finding);
            $gateFailed = $gateFailed || $finding->status === DoctorStatus::Fail;
        }

        foreach ($advisoryFindings as $finding) {
            $this->render($finding);
        }

        if ($gateFailed) {
            $this->newLine();
            $this->components->error('Base beam conformance has blocking failures — this beam app will not install/deploy clean.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The site is self-describing (ADR-0001): a valid BEAM.md must exist and declare its identity
     * (legacy SATELLITE.md still accepted during the rename). Reads + parses the front matter and
     * hands the parsed identity to the pure audit.
     */
    private function manifestFinding(string $base): Finding
    {
        $path = $this->manifestPath($base);
        $exists = is_file($path);
        $frontMatter = $exists ? $this->frontMatter((string) file_get_contents($path)) : [];

        return (new BeamManifestAudit)->run(
            $exists,
            $frontMatter['satellite'] ?? null,
            $frontMatter['variant'] ?? null,
        );
    }

    /**
     * Runtime check: is the site-mode gate actually in the `web` group? The dependency audit
     * proves marquee is declared; this proves it is enforced.
     */
    private function marqueeGateFinding(): Finding
    {
        $middleware = (string) config(
            'beam.marquee.middleware',
            'Rushing\\Marquee\\Middleware\\EnforceSiteMode',
        );

        return (new MarqueeGateAudit)->run(
            $this->laravel['router']->getMiddlewareGroups()['web'] ?? [],
            $middleware,
            (bool) config('beam.marquee.auto_register', true),
            class_exists($middleware),
        );
    }

    /**
     * Collect every Playwright MCP registration — the committed `.mcp.json` and the developer's
     * global `~/.claude.json` project entry — and prove each one isolates the browser.
     */
    private function mcpIsolationFinding(string $base): Finding
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

        return (new McpIsolationAudit)->run($registrations);
    }

    /**
     * Report-only: is the sitemap enabled, and does a static public/ file shadow the mode-aware
     * routes? Reads `beam.sitemap.*`.
     */
    private function sitemapFinding(string $base): Finding
    {
        $public = $base.'/public';
        $path = ltrim((string) config('beam.sitemap.path', 'sitemap.xml'), '/');

        $shadowing = [];
        foreach ([$path, 'robots.txt'] as $file) {
            if (is_file($public.DIRECTORY_SEPARATOR.$file)) {
                $shadowing[] = 'public/'.$file;
            }
        }

        return (new SitemapReadinessAudit)->run(
            (bool) config('beam.sitemap.enabled', true),
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

    private function render(Finding $finding): void
    {
        match ($finding->status) {
            DoctorStatus::Pass => $this->components->info($finding->check.': '.$finding->detail),
            DoctorStatus::Warn => $this->components->warn($finding->check.': '.$finding->detail),
            DoctorStatus::Fail => $this->components->error($finding->check.': '.$finding->detail),
        };
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
