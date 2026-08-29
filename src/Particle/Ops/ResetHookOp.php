<?php

namespace Splicewire\Beam\Particle\Ops;

use Illuminate\Http\Request;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Routing\IdConstraint;

/**
 * `POST /hooks/{hook}/op/reset` — the ONLY path out of auto-disable (api-surface-coherence ticket 38,
 * decided by ticket 12 §4).
 *
 * Zeroes `consecutive_failures`, drops `last_failure_request_log_id`, clears `disabled_at`.
 *
 * ## It does not clear `paused_at`, and that asymmetry is the point
 *
 * `disabled_at` is a judgement the SYSTEM made about the endpoint's health, so the owner is the right
 * party to overrule it. `paused_at` is either the owner's own intent or a lapsed entitlement (ticket
 * 13 §4), and neither of those is something a reset button should be able to overrule: a hook paused
 * because the plan lapsed must come back when the plan does. That is exactly why 13 §4 made an
 * emission-time entitlement failure PAUSE rather than fail — routing a billing lapse into
 * `consecutive_failures` would eventually auto-disable the hook and then offer the user a button
 * that cannot fix their actual problem.
 *
 * `input: false` is a DECLARATION of no input, not an omission — ticket 69's "undeclared input means
 * reject" reads a missing slot as a defect, and reset genuinely takes no body.
 *
 * The attribute DECLARES; the host ROUTES:
 *
 *     Particle::ops('hooks', 'hooks', [ResetHookOp::class]);
 *
 * ⚠️ Mount it through `Particle::ops()` / `Route::particleOps()`, which derives the route name
 * `hooks.op.reset`. A bare `Route::post()` lands in the flat name space `RouteManifest` resolves
 * LAST-WINS, silently — which has bitten this map three times.
 */
#[ParticleOp(
    resource: 'hooks',
    name: 'reset',
    kind: OperationKind::Write,
    model: Hook::class,
    ability: 'update',
    input: false,
    output: HookData::class,
    // The `{id}` shape moved off the mount and onto the declaration
    // (particle-operation-surface 14) — every host mounting this op restated `'idConstraint' => 'uuid'`
    // for a key type the model already knows.
    idConstraint: IdConstraint::Uuid,
)]
class ResetHookOp
{
    public static function handle(Hook $model, Request $request, mixed $actor): Data
    {
        $model->reset();

        return HookData::project($model->refresh());
    }
}
