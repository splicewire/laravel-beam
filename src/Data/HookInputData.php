<?php

namespace Splicewire\Beam\Data;

use Spatie\LaravelData\Attributes\Validation\ActiveUrl;
use Spatie\LaravelData\Attributes\Validation\Url;
use Spatie\LaravelData\Data;

/**
 * The WRITE DTO for the `hooks` particle resource — the `input:` slot of {@see HookData}.
 *
 * `toModelAttributes()` is the app convention `ParticleController` looks for. Absent it the
 * controller falls back to a snake-case map of whatever arrived, which is a shape nobody declared.
 *
 * ## What is NOT here, and why each absence is deliberate
 *
 * - **`secret`.** Minted by the platform ({@see \Splicewire\Beam\Models\Hook::mintSecret()}), never
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

        public ?string $token = null,

        public ?string $subject_type = null,

        public ?string $subject_id = null,

        public ?bool $paused = null,
    ) {}

    /**
     * The write map: DTO field ⇒ model column. Only keys the caller actually sent are returned, so a
     * PATCH that names one field does not null the other five.
     *
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array
    {
        $attributes = [];

        foreach (['endpoint', 'events', 'token', 'subject_type', 'subject_id'] as $field) {
            if ($this->{$field} !== null) {
                $attributes[$field] = $this->{$field};
            }
        }

        if ($this->paused !== null) {
            $attributes['paused_at'] = $this->paused ? now() : null;
        }

        return $attributes;
    }
}
