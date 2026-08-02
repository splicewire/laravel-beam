<?php

namespace Splicewire\Beam\Source;

use Illuminate\Database\Eloquent\Model;
use Rushing\Versioning\Concerns\ReconcilesPayloadOnRead;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Source\Contracts\ForeignSourceProjector;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\WriteNotAuthorized;

/**
 * The two-intensity shadow writer (ADR-0161 Position 1 + MAP §Decisions 5–7; sourced-particles ticket 06).
 * It is the ONE door a foreign source's content walks through to become a provenance-marked particle:
 *
 *   1. PROJECT the foreign payload onto the target's LOCAL schema (via {@see ForeignSourceProjector} —
 *      the ladder's foreign-source entry, ticket 04). An unschematized target throws there: ephemeral-only.
 *   2. STAMP the provenance columns (tier, external_ref, source_revision, projector_version, fetched_at)
 *      and the schema binding on the target.
 *   3. WRITE through {@see ParticleWriter} under {@see SourceWriteGate} + the {@see SourceGrant} actor —
 *      so the gate authorizes ONLY the source's granted stems, and NEVER a local tier.
 *
 * Two intensities, mirroring the writer's `$emit` flag:
 *   - {@see SourceWriteTier::ShadowCached} → a LIGHT write: the {@see BeamParticlePersisted}
 *     signal is SUPPRESSED, so the adoption side-effects it drives (embedding jobs, indexing, notification)
 *     do NOT fire. The particle + its provenance persist; no durable shared vocabulary is minted (ticket 07).
 *   - {@see SourceWriteTier::ShadowPinned} → the FULL pipeline: the signal fires once, side-effects run.
 *
 * {@see self::promote()} moves a cached record to pinned: it re-stamps the tier and runs the full pipeline
 * ONCE — the side-effects suppressed at cache time now fire. Only a `shadow-cached` record may promote.
 *
 * migrate-on-read (the target model's {@see ReconcilesPayloadOnRead}) then
 * reconciles the stored payload to the local schema's current version on every read, for free.
 *
 * ── Association carrier (sourced-particles ticket 07 tail) ──────────────────────────────────────────
 * The projector fills the target schema's REQUIRED field set; a foreign source's tag/silo associations
 * are NOT target-schema fields, so they ride ALONGSIDE the payload on a documented out-of-band carrier
 * key on the raw foreign payload: {@see self::ASSOCIATIONS_KEY} (`_associations`) — underscore-prefixed
 * to mark it as non-schema metadata the projector deliberately ignores. Shape (both arms optional):
 *
 *   "_associations": {
 *     "tags":  [{"authority"?: string, "naturalKey"|"natural_key": string, "name"?: string}, ...],
 *     "silos": [{"authority"?: string, "naturalKey"|"natural_key": string, "name"?: string}, ...]
 *   }
 *
 * On {@see self::shadow()}, after projection, this block is EXTRACTED, normalized to the shape ticket
 * 07's host reader (`App\Sourced\ParticleAssociations::fromRecord()`) expects, and stamped into the
 * record's `meta.shadow_associations` (existing `meta` keys preserved). It is stamped at SHADOW time —
 * lossless and tier-agnostic (a `cached` record carries its carrier so a later `promote()` → materialize
 * has real associations to reconcile); the associations are only ACTIONED at promote/materialize, which
 * is host-side (ticket 07). A per-association `authority` is optional and DEFAULTS to the shadow's own
 * source authority (the {@see SourceGrant}'s `sourceId`) — a foreign source's own associations belong to
 * that source unless it says otherwise. A payload with NO carrier leaves `meta.shadow_associations`
 * absent (the host reader degrades to an empty set — no empty-key noise, not an error).
 */
class ParticleShadower
{
    /** The out-of-band carrier key on the raw foreign payload where a source declares its associations. */
    public const ASSOCIATIONS_KEY = '_associations';

    public function __construct(
        private ParticleWriter $writer,
        private SchemaTargetResolver $targets,
        private ForeignSourceProjector $projector,
    ) {}

    /**
     * Shadow a foreign payload onto `$target` under `$actor`, in `$tier`, returning the persisted record.
     *
     * @param  Model|class-string<Model>  $target  a target model instance (schema-bound) or its class
     * @param  array<string, mixed>  $foreignPayload  the raw foreign source payload (any shape)
     * @param  SourceGrant  $actor  the source's write grant (its allow-list gates the write)
     * @param  SourceWriteTier  $tier  ShadowCached (light) or ShadowPinned (full) — never Local
     * @param  string  $externalRef  the item's id at its foreign authority
     * @param  string|null  $sourceRevision  the foreign revision this shadow was fetched at (content pin)
     * @param  string|null  $projectorVersion  the projector/annotation version (shape pin — double-pin)
     *
     * @throws \InvalidArgumentException the tier is Local (a source may never write a local record)
     * @throws \InvalidArgumentException the target is unschematized (projection floor — ephemeral-only)
     * @throws WriteNotAuthorized the grant does not cover the target's stem
     */
    public function shadow(
        Model|string $target,
        array $foreignPayload,
        SourceGrant $actor,
        SourceWriteTier $tier,
        string $externalRef,
        ?string $sourceRevision = null,
        ?string $projectorVersion = null,
    ): Model {
        // A source may write ONLY a shadow tier, NEVER local — the structural half of the escalation guard.
        // Enforced here BEFORE any projection/write (the gate enforces the stem allow-list; the tier rule
        // is the grant's, so it is checked at the tier-choosing call site — exactly where the MAP places it).
        if (! $actor->mayWriteTier($tier)) {
            throw new \InvalidArgumentException(
                "A source may not write the '{$tier->value}' tier — source writes are shadow-only."
            );
        }

        $model = $target instanceof Model ? $target : new $target;

        // Project the foreign payload onto the target's LOCAL schema. An unschematized target throws here
        // (ephemeral-only floor); a payload the ladder cannot reconcile throws (never a half-formed shadow).
        $targetSchema = $this->targets->targetFor($this->recordTypeOf($model));
        $payload = $this->projector->project($foreignPayload, $targetSchema);

        // Stamp provenance + tier BEFORE the write so it persists in the same save(). The writer's
        // persist seam (fillFromSchemaPayload) only touches the payload column, so these survive.
        $this->stamp($model, $tier, $externalRef, $sourceRevision, $projectorVersion);

        // Extract the foreign source's declared tag/silo associations (out-of-band carrier, NOT a schema
        // field) and stamp them into meta.shadow_associations for a later promote()→materialize to
        // reconcile (ticket 07). Absent carrier ⇒ no key stamped (host reader degrades to empty).
        $this->stampAssociations($model, $foreignPayload, $actor);

        // ShadowCached is a LIGHT write (suppress the adoption signal); ShadowPinned is the full pipeline.
        $emit = $tier !== SourceWriteTier::ShadowCached;

        return $this->writer->write($model, $payload, $actor, emit: $emit);
    }

    /**
     * Promote a `shadow-cached` record to `shadow-pinned`: re-stamp the tier and run the FULL pipeline
     * ONCE, so the adoption side-effects suppressed at cache time now fire. The record's already-projected
     * payload is re-written as-is (the shape is unchanged; promotion is a tier + side-effect act, not a
     * re-fetch). Only a `shadow-cached` record may promote.
     *
     * @throws \InvalidArgumentException the record is not `shadow-cached`
     */
    public function promote(Model $record): Model
    {
        if ($this->tierOf($record) !== SourceWriteTier::ShadowCached) {
            throw new \InvalidArgumentException(
                'Only a shadow-cached record may promote; this record is "'
                .$this->tierOf($record)->value.'".'
            );
        }

        $record->setAttribute('source_tier', SourceWriteTier::ShadowPinned);

        // Re-write the (already-projected) payload through the FULL pipeline once — the promotion fires the
        // side-effects the light cache write suppressed. The grant re-authorizes the stem in the pinned tier.
        $actor = SourceGrant::for(
            $this->sourceIdOf($record),
            array_filter([$record->getAttribute('schema_ref')]),
        );

        return $this->writer->write($record, $this->payloadOf($record), $actor, emit: true);
    }

    /**
     * Stamp the provenance + tier columns on the model before the write.
     */
    private function stamp(
        Model $model,
        SourceWriteTier $tier,
        string $externalRef,
        ?string $sourceRevision,
        ?string $projectorVersion,
    ): void {
        $model->setAttribute('source_tier', $tier);
        $model->setAttribute('external_ref', $externalRef);
        $model->setAttribute('source_revision', $sourceRevision);
        $model->setAttribute('projector_version', $projectorVersion);
        $model->setAttribute('fetched_at', now());
    }

    /**
     * Extract the foreign payload's association carrier ({@see self::ASSOCIATIONS_KEY}), normalize it to
     * the `meta.shadow_associations` shape ticket 07's host reader expects, and merge it into `meta`
     * (existing keys preserved). No carrier / nothing normalizable ⇒ `meta` is left untouched, so the
     * host reader degrades to an empty set (correct — not an error).
     *
     * @param  array<string, mixed>  $foreignPayload
     */
    private function stampAssociations(Model $model, array $foreignPayload, SourceGrant $actor): void
    {
        $carrier = $foreignPayload[self::ASSOCIATIONS_KEY] ?? null;
        if (! is_array($carrier)) {
            return;
        }

        $associations = array_filter([
            'tags' => $this->normalizeAssociations($carrier['tags'] ?? null, $actor->sourceId),
            'silos' => $this->normalizeAssociations($carrier['silos'] ?? null, $actor->sourceId),
        ]);

        // Both arms empty ⇒ don't stamp a hollow key (no empty-key noise).
        if ($associations === []) {
            return;
        }

        $meta = $model->getAttribute('meta');
        $meta = is_array($meta) ? $meta : [];
        $meta['shadow_associations'] = $associations;

        $model->setAttribute('meta', $meta);
    }

    /**
     * Normalize one association arm to a list of `{authority, naturalKey, name?}` maps. Each entry needs a
     * non-empty `naturalKey` (or `natural_key`); a per-entry `authority` is optional and DEFAULTS to
     * `$defaultAuthority` (the shadow's own source). `name` is carried through only when a non-empty string.
     *
     * @return list<array<string, string>>
     */
    private function normalizeAssociations(mixed $items, string $defaultAuthority): array
    {
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $naturalKey = $item['naturalKey'] ?? $item['natural_key'] ?? null;
            if (! is_string($naturalKey) || $naturalKey === '') {
                continue;
            }

            $authority = $item['authority'] ?? null;
            $authority = is_string($authority) && $authority !== '' ? $authority : $defaultAuthority;

            $entry = [
                'authority' => $authority,
                'naturalKey' => $naturalKey,
            ];

            if (isset($item['name']) && is_string($item['name']) && $item['name'] !== '') {
                $entry['name'] = $item['name'];
            }

            $out[] = $entry;
        }

        return $out;
    }

    /** The target's record type (schema stem) used to resolve its schema — null when it carries no binding. */
    private function recordTypeOf(Model $model): ?string
    {
        return method_exists($model, 'recordType') ? $model->recordType() : null;
    }

    /** The record's lifecycle tier — reads the enum-cast column, defaulting to Local. */
    private function tierOf(Model $record): SourceWriteTier
    {
        if (method_exists($record, 'sourceTier')) {
            return $record->sourceTier();
        }

        $tier = $record->getAttribute('source_tier');

        return $tier instanceof SourceWriteTier ? $tier : SourceWriteTier::Local;
    }

    /** The source id to re-authorize a promote under — falls back to the external ref's authority marker. */
    private function sourceIdOf(Model $record): string
    {
        $ref = $record->getAttribute('external_ref');

        return is_string($ref) && $ref !== '' ? $ref : 'promote';
    }

    /**
     * The record's stored payload to re-write on promote.
     *
     * @return array<string, mixed>
     */
    private function payloadOf(Model $record): array
    {
        $payload = $record->getAttribute('payload');

        return is_array($payload) ? $payload : [];
    }
}
