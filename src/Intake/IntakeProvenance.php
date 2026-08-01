<?php

namespace Splicewire\Beam\Intake;

use Splicewire\Beam\Models\BeamParticle;

/**
 * Optional intake-provenance facets for a record written through the public intake door
 * (beam-write-pipeline ticket 04 / ADR-0150 reversal 3): who submitted, when, from where.
 *
 * This is beam-core reopening the "beam must not learn who submitted" ruling — but bounded: provenance
 * is OPTIONAL and opt-in, recorded only on records written through the public WriteGate binding, never
 * baked into every write. It replaces the dissolved submissions package's `FormSubmission` intake
 * columns (form_key/context/user_id) with a structured facet set carried in the record's `meta`.
 *
 * A "submission" is therefore no longer a package or a model — it is a {@see BeamParticle}
 * written through the public binding, carrying these facets under `meta['intake']`.
 */
class IntakeProvenance
{
    /**
     * @param  array<string, mixed>  $context  request context (ip, user agent, …)
     */
    public function __construct(
        public string $submittedAt,
        public ?string $submittedBy = null,
        public ?string $source = null,
        public ?string $channel = null,
        public array $context = [],
    ) {}

    /**
     * The facet set as it lands under `meta['intake']` — empty facets are dropped so a record only
     * carries the provenance it actually has.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'submitted_at' => $this->submittedAt,
            'submitted_by' => $this->submittedBy,
            'source' => $this->source,
            'channel' => $this->channel,
            'context' => $this->context,
        ], static fn ($value) => $value !== null && $value !== []);
    }
}
