<?php

namespace Splicewire\Beam\Schema;

use Splicewire\Beam\Doctor\MigrationOrderingAudit;

/**
 * What {@see ConvergentTable} found and what it did about it — the value both terminals are built on:
 * `assert()` throws when {@see hasConflicts()}, `matches()` returns its negation.
 *
 * Deliberately also readable on its own, because the interesting migrations are the ones that did
 * NOTHING: a table that already matched is indistinguishable at the database from a table this run
 * created, and a host chasing an install is entitled to know which happened.
 *
 * `unverified` is the conservative posture inherited from {@see MigrationOrderingAudit}
 * — a declared column type this build has no driver mapping for is REPORTED, never guessed at and never
 * escalated to a conflict. A false conflict stops an install; a missed one is the status quo.
 */
class ConvergenceReport
{
    /**
     * @param  list<string>  $addedColumns
     * @param  list<string>  $addedIndexes
     * @param  list<SchemaConflict>  $conflicts
     * @param  list<string>  $unverified  columns whose declared type has no mapping for this driver
     */
    public function __construct(
        public string $table,
        public bool $created = false,
        public array $addedColumns = [],
        public array $addedIndexes = [],
        public array $conflicts = [],
        public array $unverified = [],
    ) {}

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    /** True when the live table now carries every column this migration declares. */
    public function converged(): bool
    {
        return ! $this->hasConflicts();
    }

    /** True when the table already matched and nothing was written. */
    public function unchanged(): bool
    {
        return ! $this->created && $this->addedColumns === [] && $this->addedIndexes === [];
    }

    public function summary(): string
    {
        if ($this->created) {
            return "created `{$this->table}`";
        }

        if ($this->hasConflicts()) {
            return "`{$this->table}` cannot converge (".count($this->conflicts).' conflict'
                .(count($this->conflicts) === 1 ? '' : 's').')';
        }

        if ($this->unchanged()) {
            return "`{$this->table}` already matches";
        }

        $parts = [];

        if ($this->addedColumns !== []) {
            $parts[] = 'added '.implode(', ', $this->addedColumns);
        }

        if ($this->addedIndexes !== []) {
            $parts[] = 'indexed '.implode(', ', $this->addedIndexes);
        }

        return "converged `{$this->table}`: ".implode('; ', $parts);
    }
}
