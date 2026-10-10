<?php

namespace Splicewire\Beam\Data;

use Illuminate\Database\Eloquent\Builder;
use Schemastud\Frame\Attributes\Column;
use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Webhooks\Data\CreatedHookData;
use Splicewire\Beam\Webhooks\HookFrameResourceHandler;
use Splicewire\Beam\Webhooks\HookSubscriptionReach;

/**
 * The READ projection for the `hooks` particle resource, and its declaration site
 * (api-surface-coherence ticket 38, decided by ticket 12).
 *
 * `data:` is the read projection, `input:` the write DTO ({@see HookInputData}), and
 * `createResultData:` the reveal-once Frame create result ({@see CreatedHookData}).
 *
 * The attribute DECLARES; the host ROUTES. Read/update exposures may mount this one declaration
 * in several places; creation uses the canonical Frame resource endpoint:
 *
 *     Particle::mount('hooks')->except(['store']);              // root
 *     Particle::mount('{resource}/hooks', 'hooks')->except(['store']); // scoped
 *     Particle::mount('hooks')->except(['store']);              // operator realm
 *
 * Group is **Platform** (12 §9): a hook is not about any one resource — the scoped exposure is a
 * filter over the same rows — so filing it under the resource it happens to be viewed through would
 * put the same record in twenty places in the nav.
 *
 * ## `secret` is not on this class, and that is the whole reveal-once design
 *
 * The minted secret is returned exactly once, by the declared Frame create handler, following the
 * `tokens` precedent (`TokenData.php:20-24`). Every subsequent read — index, show, the scoped
 * projection, the operator realm — projects {@see $secret_preview} and nothing more. A `secret`
 * property here would be revealed by every one of them, and no amount of route-level care would fix
 * it, because the projection is the thing the routes share.
 *
 * `BeamData` is beam's own base class, resolved as a sibling in this namespace and so left
 * unimported. Beam ships it so every DTO answers `::jsonSchema()` through the host's configured
 * generator (`66e2dff`) — a particle-declared DTO inside beam that skipped it was the one shape
 * beam's own doctrine could not describe.
 */
#[ParticleResource(
    key: 'hooks',
    backing: Hook::class,
    data: HookData::class,
    input: HookInputData::class,
    editData: HookInputData::class,
    createResultData: CreatedHookData::class,
    handler: HookFrameResourceHandler::class,
    label: 'Hooks',
    singularLabel: 'Hook',
    group: 'Platform',
    icon: 'webhook',
    section: 'operator-system', // the operator rail's System task section (ux-walkthrough UX-09, IA-10; lead 10:04Z)
    // The create affordance is the HOST's. Frame's generic "New" opens the generic create form, and a
    // hook's create is not generic: it MINTS A SECRET that is returned exactly once, so the flagship's
    // HooksPage owns a bespoke create dialog plus a reveal-once follow-up dialog. The page was spelling
    // this by hand as `Toolbar: () => null`; the declaration says it now.
    //
    // ⚠️ Presentation only — `hooks` stays fully creatable (`readOnly` is false and untouched). This slot
    // moves who draws the button, never whether the write is open.
    createAffordance: 'host',
)]
class HookData extends BeamData
{
    // Read projection, camel on the wire (owner ruling 2026-10-09 18:18Z; docs/agents/wire-name.convention.md):
    // camel properties pinned with #[MapName] so no host output mapper floats them, snake columns mapped
    // explicitly in project(). No snake alias is published.
    public function __construct(
        public string $id,

        #[Column(label: 'Endpoint', sort: 0)]
        public string $endpoint,

        /** @var list<string> */
        #[Column(label: 'Events', sort: 1)]
        public array $events,

        #[MapName('subjectType')]
        public ?string $subjectType,
        #[MapName('subjectId')]
        public ?string $subjectId,

        /** User intent — the owner switched it off, or a lapsed entitlement paused it (13 §4). */
        #[Column(label: 'Paused', sort: 2)]
        #[MapName('pausedAt')]
        public ?string $pausedAt,

        /** System health — auto-disabled after `consecutive_failures`. `op/reset` is the way back. */
        #[Column(label: 'Disabled', sort: 3)]
        #[MapName('disabledAt')]
        public ?string $disabledAt,

        #[Column(label: 'Failures', sort: 4)]
        #[MapName('consecutiveFailures')]
        public int $consecutiveFailures,

        #[MapName('lastFailureRequestLogId')]
        public ?string $lastFailureRequestLogId,

        #[Column(label: 'Verified', sort: 5)]
        #[MapName('verifiedAt')]
        public ?string $verifiedAt,

        /** Enough to recognise the secret you saved. Never enough to sign with. */
        #[MapName('secretPreview')]
        public string $secretPreview,

        /** Both off-switches clear — the one boolean a UI actually wants, derived rather than stored. */
        public bool $deliverable,

        #[MapName('createdAt')]
        public ?string $createdAt,
    ) {}

    /**
     * Re-vet the subscription's REACH on every particle write (particle-write-surface ticket 04).
     *
     * `ParticleController` runs this convention hook on create AND update
     * (`ParticleController:226` / `:241`), which is the whole point: the update path authorizes the
     * Hook ROW — "may this actor update this hook?" — and until this existed, nothing re-asked the
     * DIFFERENT question `POST /hooks` asks, "may this actor receive these events for that subject?"
     *
     * Measured 2026-08-27 with the gate CLOSED (explicit policies, no `Gate::before`): a
     * `PUT /hooks/{id}` carrying `subject_type`/`subject_id` re-pointed a hook at a record the actor
     * could not `view`, answered **200**, and persisted it. It now 403s.
     *
     * The hook is a no-op unless the write MOVES the subscription — see
     * {@see HookSubscriptionReach::vetWrite()} for why the check is on the delta.
     */
    public static function prepare(Hook $hook, mixed $input, mixed $actor = null): void
    {
        if (! $input instanceof HookInputData) {
            return;
        }

        app(HookSubscriptionReach::class)->vetWrite($hook, $input, $actor);
    }

    public static function project(Hook $model): BeamData
    {
        return new self(
            id: (string) $model->id,
            endpoint: $model->endpoint,
            events: array_values((array) $model->events),
            subjectType: $model->subject_type,
            subjectId: $model->subject_id === null ? null : (string) $model->subject_id,
            pausedAt: $model->paused_at?->toIso8601String(),
            disabledAt: $model->disabled_at?->toIso8601String(),
            consecutiveFailures: (int) $model->consecutive_failures,
            lastFailureRequestLogId: $model->last_failure_request_log_id,
            verifiedAt: $model->verified_at?->toIso8601String(),
            secretPreview: $model->secretPreview(),
            deliverable: $model->deliverable(),
            createdAt: $model->created_at?->toIso8601String(),
        );
    }

    /**
     * Every persisted hook on the mounted connection, newest first. Deliberately NOT scoped by `owner_*`:
     * ticket 12 §7 made the owner morph AUDIT
     * ONLY, and a scope here would quietly turn it into an authorization boundary that nothing else
     * in the surface honours — which is the worse of the two failures, because it would look like it
     * worked.
     *
     * @param  Builder<Hook>  $q
     * @return Builder<Hook>
     */
    public static function scope(Builder $q): Builder
    {
        return $q->whereNotNull($q->getModel()->getQualifiedKeyName())->latest();
    }
}
