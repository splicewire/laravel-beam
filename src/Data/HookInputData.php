<?php

namespace Splicewire\Beam\Data;

use Spatie\LaravelData\Attributes\Validation\ActiveUrl;
use Spatie\LaravelData\Attributes\Validation\Url;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Webhooks\HookSubscriptionReach;

/**
 * The WRITE DTO for the `hooks` particle resource — the `input:` slot of {@see HookData}.
 *
 * `toModelAttributes()` is the app convention `ParticleController` looks for. Absent it the
 * controller falls back to a snake-case map of whatever arrived, which is a shape nobody declared.
 *
 * ## What is NOT here, and why each absence is deliberate
 *
 * - **`secret`.** Minted by the platform ({@see Hook::mintSecret()}), never
 *   supplied. A caller-chosen HMAC key is a caller-chosen weak HMAC key.
 * - **`disabled_at`, `consecutive_failures`.** System health. The only write path is `op/reset`,
 *   which is the acceptance criterion "op/reset is the only path out of auto-disable" — making them
 *   writable here would give a second, silent one.
 * - **`verified_at`.** Set by the `hooks.ping` round trip, not by assertion.
 * - **`entitlement_keys`.** Snapshotted from the `entitlement:*` middleware on the route the request
 *   arrived through (13 §6). A caller-supplied value would be a caller granting itself a feature.
 * - **`owner_*`.** Stamped from the authenticated principal at create.
 *
 * `paused_at` IS writable, as a boolean rather than a timestamp: pausing is user intent, and a user
 * choosing WHEN they paused is meaningless.
 *
 * ## The three input states, and which fields can tell them apart
 *
 * A PATCH body can say three different things about a field: it can be *absent* ("leave it alone"),
 * *present-and-null* ("clear it"), or *present with a value*. A promoted property written
 * `public ?T $x = null` can only ever express TWO of them — `DefaultValuesDataPipe` checks
 * `hasDefaultValue` BEFORE `type->isOptional`, so the declared default wins and an absent field
 * arrives as `null`, indistinguishable from a submitted one. On a `!== null` gate the two collapse,
 * and the collapse is one-directional: the column can be set and can never be cleared.
 *
 *   - **`token`** is `string|Optional|null` with NO `= null` default (the default is the sentinel
 *     itself). Removing the `= null` is the whole fix; putting it back makes the `Optional` arm
 *     unreachable again. Absent ⇒ untouched · present-and-null ⇒ written as null, revoking the
 *     bearer · value ⇒ written. It is the additive receiver-known bearer, orthogonal to `secret`, so
 *     "stop sending an Authorization header" is a real subscriber intent that previously had no wire
 *     representation.
 *   - **`endpoint`, `events`** are NOT NULL in `create_beam_hooks_table`. Clearing either is a
 *     constraint violation, not an affordance; dropping the null is the correct no-op.
 *   - **`paused`** already has all three states without `Optional`: `true` stamps `paused_at`,
 *     `false` clears it, absent leaves it. The boolean IS the tri-state, so there is nothing to fix.
 *   - **`subject_type`/`subject_id`** are nullable in the table and are still `?string = null` here, so
 *     the pair can be SET and re-pointed but not CLEARED. The authorization objection that held them
 *     back is GONE as of particle-write-surface ticket 04: the reach check now lives in
 *     {@see HookSubscriptionReach} and {@see HookData::prepare()} asks it on the particle write path
 *     as well as `POST /hooks`, so a re-point at an unreachable record 403s (measured with the gate
 *     closed; before the repair it answered 200 and persisted). Clearing the pair — which BROADENS a
 *     narrowed subscription into a firehose over the whole resource — is vetted by the same call: an
 *     empty pair falls to the subjectless branch, which demands `viewAny` on every resource the event
 *     set spans.
 *
 *     So converting these two to `string|Optional|null` (no `= null` default, per the `token` note
 *     above) is now the one-line change the docblock has promised since ticket 01, and it is safe. It
 *     is left to ticket 01's own follow-up rather than done here, so the security repair lands as a
 *     security repair and the surface widening is a separate, separately-reviewed diff.
 */
class HookInputData extends Data
{
    /**
     * @param  list<string>|null  $events  catalog event names; validated against the live catalog at
     *                                     subscribe time, which is a HOST fact and therefore reported
     *                                     rather than thrown at boot (ticket 91's rule)
     */
    public function __construct(
        #[Url]
        #[ActiveUrl]
        public ?string $endpoint = null,

        /** @var list<string>|null */
        public ?array $events = null,

        /**
         * The additive caller-supplied bearer — nullable in the column, and CLEARABLE, because revoking
         * it is a real subscriber intent. `string|Optional|null` with no `= null` default, so an absent
         * field is the `Optional` sentinel and an explicit null is a real null that reaches the column.
         * See the class docblock; do not restore the default.
         */
        public string|Optional|null $token = new Optional,

        /**
         * The narrowing target — nullable in the column, and CLEARABLE, because widening a hook back to
         * the whole resource is a real subscriber intent. `string|Optional|null` with no `= null`
         * default, so an absent field is the `Optional` sentinel and an explicit null is a real null.
         *
         * ⚠️ **This pair is only safe to clear because {@see HookSubscriptionReach::vetWrite()} is
         * `Optional`-aware.** It was held back by particle-write-surface ticket 01 precisely because
         * clearing broadens a narrowed subscription into a firehose over the whole resource, and until
         * ticket 04 the update path re-ran no reach check at all. Even after 04, `vetWrite()` computed
         * the intended subject with `??`, which cannot distinguish absent from an explicit null — so
         * converting these two without changing that computation would have written the clear while
         * skipping the re-vet. The two changes are one change; do not split them.
         */
        public string|Optional|null $subject_type = new Optional,

        public string|Optional|null $subject_id = new Optional,

        public ?bool $paused = null,
    ) {}

    /**
     * The write map: DTO field ⇒ model column. Only keys the caller actually sent are returned, so a
     * PATCH that names one field does not null the other five.
     *
     * Two gates, deliberately. `endpoint`/`events`/`subject_*` drop their nulls. `token` is gated on
     * PRESENCE instead, because an explicit null there is a caller revoking the bearer and that is the
     * one thing the null-dropping gate cannot express. See the class docblock for why `subject_*` is
     * not in that second group.
     *
     * Explicit per-field checks, never `get_object_vars`, which would leak `Optional` sentinels onto the
     * write.
     *
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        $attributes = [];

        foreach (['endpoint', 'events'] as $field) {
            if ($this->{$field} !== null) {
                $attributes[$field] = $this->{$field};
            }
        }

        // Absent ⇒ leave the column alone. Present ⇒ write it, INCLUDING a null. For `token` a null
        // revokes the bearer the receiver was checking for; for `subject_*` it widens the hook back to
        // the whole resource, which `HookSubscriptionReach::vetWrite()` authorizes on the subjectless
        // plane before this ever runs. See each property's note.
        foreach (['token', 'subject_type', 'subject_id'] as $field) {
            if (! $this->{$field} instanceof Optional) {
                $attributes[$field] = $this->{$field};
            }
        }

        if ($this->paused !== null) {
            $attributes['paused_at'] = $this->paused ? now() : null;
        }

        return $attributes;
    }
}
