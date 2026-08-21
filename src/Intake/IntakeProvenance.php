<?php

namespace Splicewire\Beam\Intake;

use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Models\BeamSubmission;

/**
 * Optional intake-provenance facets for a record written through the public intake door
 * (beam-write-pipeline ticket 04 / ADR-0150 reversal 3): who submitted, when, from where.
 *
 * This is beam-core reopening the "beam must not learn who submitted" ruling — but bounded: provenance
 * is OPTIONAL and opt-in, recorded only on records written through the public WriteGate binding, never
 * baked into every write. It replaces the dissolved submissions package's `FormSubmission` intake
 * columns (form_key/context/user_id) with a structured facet set carried in the record's `meta`.
 *
 * A "submission" is therefore no longer a PACKAGE — but it is still a model: the door writes the
 * canonical {@see BeamSubmission} (beam-facade ticket 51), carrying these facets under
 * `meta['intake']`. It wrote the populator-agnostic {@see BeamParticle} between ticket 04 and 51,
 * which is why this class's `form_key`/`context`/`user_id` framing above reads as a replacement
 * rather than as the columns the door now fills alongside it.
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
