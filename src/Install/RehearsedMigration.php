<?php

namespace Splicewire\Beam\Install;

use Rushing\SchemaConvergence\ConvergenceReport;
use Rushing\SchemaConvergence\SchemaConflict;

/**
 * One pending migration as {@see ConvergencePreflight} found it: either the convergence reports its
 * guards would produce, or the reason it could not be asked.
 *
 * BOTH OUTCOMES ARE RESULTS, which is why they are one class rather than two lists. A preflight that
 * printed only what it managed to rehearse would read as "everything is clean" to an operator whose one
 * risky migration is the one it silently skipped — the same false-green shape the convergent guard
 * exists to end, reappearing in the tool that reports on it. So a skip is displayed, counted, and named.
 */
final class RehearsedMigration
{
    /**
     * @param  string  $migration  the name the migrator records — filename without `.php`
     * @param  list<ConvergenceReport>  $reports  one per convergent guard the body reached, in body order
     * @param  string|null  $skipped  why it was not rehearsed; null when it was
     */
    public function __construct(
        public readonly string $migration,
        public readonly string $file,
        public readonly array $reports = [],
        public readonly ?string $skipped = null,
    ) {}

    public static function skip(string $migration, string $file, string $reason): self
    {
        return new self($migration, $file, skipped: $reason);
    }

    public function wasRehearsed(): bool
    {
        return $this->skipped === null;
    }

    /** @return list<SchemaConflict> */
    public function conflicts(): array
    {
        return array_merge(...array_map(fn (ConvergenceReport $r) => $r->conflicts, $this->reports));
    }

    public function hasConflicts(): bool
    {
        return $this->conflicts() !== [];
    }

    /** Reports whose run would write DDL — the "not already matching" set. */
    public function changes(): array
    {
        return array_values(array_filter(
            $this->reports,
            fn (ConvergenceReport $r) => ! $r->hasConflicts() && $r->changesSchema(),
        ));
    }
}
