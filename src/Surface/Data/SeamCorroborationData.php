<?php

namespace Splicewire\Beam\Surface\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\Data;

/**
 * The result of comparing one document against one runtime: per-seam **agreement**, **disagreement**,
 * and **gap**.
 *
 * The three are kept in separate lists rather than as a `status` on one list because they are read at
 * different urgencies and by different people. Agreement is the boring majority and exists only so the
 * denominator is honest; the disagreements are the work-list; the gaps are the "we could not tell"
 * column that must never quietly merge into either neighbour.
 *
 * `mode` is on the envelope, not buried in a note, so a spec-only run cannot be mistaken for a
 * corroborated one by a reader who skims. Every finding also carries its own
 * {@see SurfaceFindingData::$provenanceRank}, so the distinction survives being flattened into a report.
 */
#[TypeScript]
class SeamCorroborationData extends Data
{
    /** A live runtime was walked; findings can reach the strong rank. */
    public const MODE_CORROBORATED = 'corroborated';

    /** No runtime binding — the document is all we have, and every finding is floored to the weak rank. */
    public const MODE_SPEC_ONLY = 'spec-only';

    /**
     * @param  list<SurfaceFindingData>  $agreements
     * @param  list<SurfaceFindingData>  $disagreements
     * @param  list<SurfaceFindingData>  $gaps
     */
    public function __construct(
        public string $mode,
        public array $agreements = [],
        public array $disagreements = [],
        public array $gaps = [],
        public int $specSeamCount = 0,
        public int $runtimeRouteCount = 0,
    ) {}

    public function isCorroborated(): bool
    {
        return $this->mode === self::MODE_CORROBORATED;
    }

    /**
     * The strongest rank any finding in this result carries. A spec-only run can never exceed
     * {@see SurfaceFindingData::RANK_DOCUMENTED}, which is what makes the floor structural rather than
     * a matter of how the report is worded.
     */
    public function strongestRank(): int
    {
        $ranks = array_map(
            fn (SurfaceFindingData $finding) => $finding->provenanceRank,
            [...$this->agreements, ...$this->disagreements, ...$this->gaps],
        );

        return $ranks === [] ? SurfaceFindingData::RANK_DOCUMENTED : max($ranks);
    }

    /** @return list<SurfaceFindingData> */
    public function all(): array
    {
        return [...$this->disagreements, ...$this->gaps, ...$this->agreements];
    }
}
