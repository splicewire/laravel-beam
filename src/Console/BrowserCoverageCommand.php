<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Doctor\Support\BrowserCoverageReader;
use Splicewire\Beam\Doctor\Support\BrowserCoverageReport;
use Splicewire\Beam\Doctor\Support\BrowserSuiteInventory;

/**
 * `php artisan splicewire:beam:browser-coverage <junit>` — the exit-code half of browser-journeys 13's
 * coverage check (ticket 03 Q6). It reads the JUnit a Pest browser run wrote and FAILS the command when any
 * installed `splicewire/*` browser suite that ships tests produced zero testcases — the `not-collected`
 * verdict the Sweep gates on, so a run that silently skipped a mounted suite cannot report success.
 *
 * Read-only: it inspects the JUnit and the install, writes nothing. {@see BrowserCoverageReader} holds the
 * logic; this command only maps its {@see BrowserCoverageReport::ok()} to an exit code and prints the rows.
 */
class BrowserCoverageCommand extends Command
{
    protected $signature = 'splicewire:beam:browser-coverage
        {junit : Path to the JUnit XML a browser run wrote (pest --log-junit)}';

    protected $description = 'Verify every installed splicewire/* browser suite was collected in a JUnit run; non-zero on any not-collected suite.';

    public function handle(): int
    {
        $junit = (string) $this->argument('junit');

        $report = (new BrowserCoverageReader(
            new BrowserSuiteInventory($this->laravel->basePath()),
        ))->read($junit);

        if ($report->items === []) {
            $this->components->info('No installed splicewire/* package ships a tests/Browser suite — nothing to verify.');

            return self::SUCCESS;
        }

        foreach ($report->items as $item) {
            $line = sprintf('%s: %s — %s', $item['package'], $item['status'], $item['detail']);
            match ($item['status']) {
                BrowserCoverageReport::PROVED, BrowserCoverageReport::SKIPPED => $this->components->info($line),
                default => $this->components->error($line),
            };
        }

        if (! $report->ok()) {
            $this->newLine();
            $this->components->error(sprintf(
                '%d browser suite(s) not collected and %d failed — this run did not prove the installed suites.',
                count($report->notCollected()),
                count($report->failing()),
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
