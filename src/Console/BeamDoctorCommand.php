<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\BeamDependencyContractAudit;
use Splicewire\Beam\Doctor\FrameManifestAudit;
use Splicewire\Beam\Doctor\SchemaFormsDoorAudit;
use Splicewire\Beam\Doctor\SchemaRoundTripAudit;

/**
 * `php artisan splicewire:beam:doctor` — base-tier Beam readiness. Moat-free: it never
 * requires splicewire/laravel-satellite, and every frame / schema-forms / data-schemas
 * check is advisory and presence-conditional (class_exists / app()->bound at runtime).
 *
 * The one hard gate is the dependency contract (no path refs in the committed lock, no
 * committed path repositories) — the only audit that can fail the exit code. Everything
 * else renders as Pass/Warn but never turns the run red: a headless beam app with no
 * editor rung installed is a valid, green configuration (ADR-0082 / ADR-0095).
 *
 * Output format (the parse target for a future <DoctorOutput>): each finding renders as one
 * line `<check>: <detail>` at info (Pass) / warn (Warn) / error (Fail).
 */
class BeamDoctorCommand extends Command
{
    protected $signature = 'splicewire:beam:doctor';

    protected $description = 'Base-tier Beam readiness (moat-free; frame/schema-forms/data-schemas checks are advisory when present).';

    public function handle(): int
    {
        $base = $this->laravel->basePath();

        $composerJson = $this->readJson($base.'/composer.json');

        if ($composerJson === null) {
            $this->components->error('No readable composer.json at '.$base.'.');

            return self::FAILURE;
        }

        // THE hard gate — only these findings can fail the exit code.
        $contractFindings = (new BeamDependencyContractAudit)->run(
            $composerJson,
            $this->readJson($base.'/composer.lock'),
        );

        // Advisory, presence-conditional — never fail the exit code.
        $advisoryFindings = [
            (new SchemaRoundTripAudit)->run(),
            (new FrameManifestAudit)->run(),
            (new SchemaFormsDoorAudit)->run(),
        ];

        $contractFailed = false;

        foreach ($contractFindings as $finding) {
            $this->render($finding);
            $contractFailed = $contractFailed || $finding->status === DoctorStatus::Fail;
        }

        foreach ($advisoryFindings as $finding) {
            $this->render($finding);
        }

        if ($contractFailed) {
            $this->newLine();
            $this->components->error('Dependency contract has blocking failures — this beam app will not install on a clean box.');

            return self::FAILURE;
        }

        return self::SUCCESS;
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
