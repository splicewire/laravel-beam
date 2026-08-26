<?php

namespace Splicewire\Beam\Data;

use Illuminate\Database\Eloquent\Builder;
use Schemastud\Frame\Attributes\Column;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The READ projection for the `hooks` particle resource, and its declaration site
 * (api-surface-coherence ticket 38, decided by ticket 12).
 *
 * `data:` and `input:` are the resource's two shape slots — the read projection (this class) and the
 * write DTO ({@see HookInputData}).
 *
 * The attribute DECLARES; the host ROUTES. Ticket 12 §1 gives it three exposures, and they are three
 * mounts of this ONE declaration, not three resources:
 *
 *     Particle::mount('hooks');                                 // root
 *     Particle::mount('{resource}/hooks', 'hooks');             // scoped, prefix-filtered
 *     Particle::mount('hooks');                                 // again, inside the operator realm
 *
 * Group is **Platform** (12 §9): a hook is not about any one resource — the scoped exposure is a
 * filter over the same rows — so filing it under the resource it happens to be viewed through would
 * put the same record in twenty places in the nav.
 *
 * ## `secret` is not on this class, and that is the whole reveal-once design
 *
 * The minted secret is returned exactly once, by the hand-rolled create endpoint, following the
 * `tokens` precedent (`TokenData.php:20-24`). Every subsequent read — index, show, the scoped
 * projection, the operator realm — projects {@see $secret_preview} and nothing more. A `secret`
 * property here would be revealed by every one of them, and no amount of route-level care would fix
 * it, because the projection is the thing the routes share.
 */
#[ParticleResource(
    key: 'hooks',
    backing: Hook::class,
    data: HookData::class,
    input: HookInputData::class,
    label: 'Hooks',
    singularLabel: 'Hook',
    group: 'Platform',
    icon: 'webhook',
    section: 'platform',
)]
class HookData extends Data
{
    public function __construct(
        public string $id,

        #[Column(label: 'Endpoint', sort: 0)]
        public string $endpoint,

        /** @var list<string> */
        #[Column(label: 'Events', sort: 1)]
        public array $events,

        public ?string $subject_type,
        public ?string $subject_id,

        /** User intent — the owner switched it off, or a lapsed entitlement paused it (13 §4). */
        #[Column(label: 'Paused', sort: 2)]
        public ?string $paused_at,

        /** System health — auto-disabled after `consecutive_failures`. `op/reset` is the way back. */
        #[Column(label: 'Disabled', sort: 3)]
        public ?string $disabled_at,

        #[Column(label: 'Failures', sort: 4)]
        public int $consecutive_failures,

        public ?string $last_failure_request_log_id,

        #[Column(label: 'Verified', sort: 5)]
        public ?string $verified_at,

        /** Enough to recognise the secret you saved. Never enough to sign with. */
        public string $secret_preview,

        /** Both off-switches clear — the one boolean a UI actually wants, derived rather than stored. */
        public bool $deliverable,

        public ?string $created_at,
    ) {}

    public static function project(Hook $model): Data
    {
        return new self(
            id: (string) $model->id,
            endpoint: $model->endpoint,
            events: array_values((array) $model->events),
            subject_type: $model->subject_type,
            subject_id: $model->subject_id === null ? null : (string) $model->subject_id,
            paused_at: $model->paused_at?->toIso8601String(),
            disabled_at: $model->disabled_at?->toIso8601String(),
            consecutive_failures: (int) $model->consecutive_failures,
            last_failure_request_log_id: $model->last_failure_request_log_id,
            verified_at: $model->verified_at?->toIso8601String(),
            secret_preview: $model->secretPreview(),
            deliverable: $model->deliverable(),
            created_at: $model->created_at?->toIso8601String(),
        );
    }

    /**
     * Newest first. Deliberately NOT scoped by `owner_*`: ticket 12 §7 made the owner morph AUDIT
     * ONLY, and a scope here would quietly turn it into an authorization boundary that nothing else
     * in the surface honours — which is the worse of the two failures, because it would look like it
     * worked.
     *
     * @param  Builder<Hook>  $q
     * @return Builder<Hook>
     */
    public static function scope(Builder $q): Builder
    {
        return $q->latest();
    }
}
