<?php

namespace Splicewire\Beam\Surgeon;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;
use Splicewire\Beam\Doctor\BeamDoctorManifest;

/**
 * Diagnoses house-style drift — files carrying a forbidden modern-PHP construct (`declare(strict_types=1)`,
 * `final`, `readonly`) — across the given roots, and pairs each offending file with the deterministic
 * {@see HouseStyleStripOperation} that removes it (the ticket-07 {@see SuggestsOperations} bridge).
 *
 * One {@see FixableFinding} per offending FILE (not per construct), so the finding-set stays a per-file
 * fix unit: the paired {@see OperationSuggestion} nominates `house-style-strip` with the file as its
 * payload, and the detail line lists which constructs the file carries. A clean scan yields a single
 * aggregate Pass. Detection is the operation's own token-aware planner run in a check posture — the audit
 * never writes.
 *
 * **Dual-channel** (the ConformanceSweep "two channels, one vocabulary" contract): it implements BOTH
 * {@see SuggestsOperations} (surgeon's fix-half — the richer paired channel `surgeon:audit` prefers) and
 * {@see DoctorAudit} (the plain finding-half beam's own `splicewire:beam:doctor` iterates via `run()`), so
 * one registration in {@see BeamDoctorManifest} serves both consumers.
 */
class HouseStyleAudit implements DoctorAudit, SuggestsOperations
{
    /** @param  list<string>  $roots  files or directories to scan for forbidden constructs */
    public function __construct(
        public array $roots,
        private HouseStyleStripOperation $operation = new HouseStyleStripOperation,
    ) {}

    /**
     * The plain finding-half for beam's own doctor command (the {@see DoctorAudit} channel): the same
     * diagnosis as {@see suggestOperations()} with the fix-suggestion dropped.
     *
     * @return list<Finding>
     */
    public function run(): array
    {
        return array_map(fn (FixableFinding $f) => $f->finding, $this->suggestOperations());
    }

    /** @return list<FixableFinding> */
    public function suggestOperations(): array
    {
        if ($this->roots === []) {
            return [new FixableFinding(Finding::inconclusive('house-style.no-roots', 'No roots to scan.'))];
        }

        $findings = [];

        foreach ($this->phpFiles() as $file => $relativePath) {
            $plan = $this->operation->plan($file, $relativePath);
            if ($plan->isEmpty()) {
                continue;
            }

            $findings[] = new FixableFinding(
                Finding::warn(
                    'house-style.forbidden-modifier',
                    "{$relativePath}: forbidden construct(s) — ".implode(', ', array_unique($plan->reasons)).'.',
                ),
                new OperationSuggestion(
                    $this->operation->kind(),
                    "Strip the forbidden construct(s) from {$relativePath}.",
                    ['file' => $file],
                ),
            );
        }

        if ($findings === []) {
            return [new FixableFinding(Finding::pass('house-style.clean', 'No forbidden modern-PHP constructs found.'))];
        }

        return $findings;
    }

    /**
     * Every `.php` file under the roots (a root may itself be a file), keyed absolute-path => relative.
     * `vendor/` and `node_modules/` are skipped — the strip is a source-tree operation, never a vendored one.
     *
     * @return array<string, string>
     */
    private function phpFiles(): array
    {
        $files = [];

        foreach ($this->roots as $root) {
            if (is_file($root)) {
                if (str_ends_with($root, '.php')) {
                    $files[$root] = basename($root);
                }

                continue;
            }
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $entry) {
                /** @var \SplFileInfo $entry */
                $path = $entry->getPathname();
                if (! $entry->isFile() || ! str_ends_with($path, '.php')) {
                    continue;
                }
                if (preg_match('#[/\\\\](vendor|node_modules)[/\\\\]#', $path) === 1) {
                    continue;
                }
                $files[$path] = ltrim(substr($path, strlen($root)), '/\\');
            }
        }

        ksort($files);

        return $files;
    }
}
