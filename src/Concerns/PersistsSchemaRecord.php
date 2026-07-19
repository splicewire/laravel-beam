<?php

namespace Splicewire\Beam\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The generic schema-record skeleton: turn an Eloquent model into a server-persisted,
 * schema-form-editable record. This is the narrow, populator-agnostic core — nothing
 * here knows about generation, submission, or any one way the payload was produced.
 *
 * It composes Laravel's {@see HasUuids} (a time-ordered **uuid7** primary key — never a
 * hand-rolled v4 `Str::uuid()` creating hook) and casts the two generic columns:
 *
 *   payload (json) — the materialised object the schema form edits
 *   meta    (json) — schema-form-agnostic derived/annotation facts
 *
 * {@see self::extract()} is a present-but-inert seam for projecting derived rows (facets,
 * cards, search documents) out of the payload — any populator may override it.
 *
 * Provenance for a *particular* populator (generation grounding, submission context, …)
 * is NOT here: it composes OVER this trait as a reference overlay in the owning domain
 * package (composition, not inheritance). beam names no domain package — the dependency
 * runs one way (domain -> beam), never the reverse.
 *
 * @mixin Model
 */
trait PersistsSchemaRecord
{
    use HasUuids;

    public function initializePersistsSchemaRecord(): void
    {
        $this->mergeCasts([
            'payload' => 'array',
            'meta' => 'array',
        ]);
    }

    /**
     * Seam for projecting derived rows (facets, cards, search documents, …) out of the
     * persisted payload. Inert by default; a host model overrides it when a record should
     * fan out into queryable child records.
     */
    public function extract(): void
    {
        //
    }
}
