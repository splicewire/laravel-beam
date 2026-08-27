<?php

namespace Splicewire\Beam\Doctor\Support;

/**
 * One table named at one call site in a migration — what {@see MigrationTableScanner} emits.
 *
 * The type exists because the scanner has three answers, not two, and a tuple flattens the third into
 * the second. A call site either names a table **literally**, names one **through the prefix seam**
 * (`Beam::table('particles')` — a literal the SCANNER cannot resolve but the booted container can), or
 * names one in a shape nobody should guess at (`$this->target()`, `$tableNames['roles']`). Collapsing
 * the middle case into "unresolved" is what left the estate's dynamic half invisible; collapsing it
 * into "resolved" is what would produce the confident wrong warning
 * `MigrationOrderingAudit::tablesIn()` was right to refuse.
 *
 * So {@see $name} is the literal the scanner read, {@see $prefixed} says whether it still has to go
 * through `Beam::table()`, and a `$name` of `null` is the honest third answer: this site names a table
 * and the scanner declines to say which.
 */
class MigrationTableRef
{
    public function __construct(
        /** 1-based source line of the call. */
        public int $line,
        /** The call as written — `ConvergentTable::named`, `Schema::create`, `->constrained`, … */
        public string $shape,
        /** The literal read out of the argument, or `null` when the shape is not one we resolve. */
        public ?string $name,
        /** Whether {@see $name} is a bare table name still owed the host's prefix. */
        public bool $prefixed = false,
        /** How the name was arrived at, for a finding's detail line: `literal`, `prefixed`, `column`. */
        public string $via = 'literal',
    ) {}

    /** Whether this site named a table the scanner declined to resolve. */
    public function isOpaque(): bool
    {
        return $this->name === null;
    }
}
