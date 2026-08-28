<?php

namespace Splicewire\Beam\Webhooks\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Models\Hook;

/**
 * The one and only response that ever carries a hook's signing secret in the clear
 * (api-surface-coherence ticket 38, following the `tokens` reveal-once precedent recorded in
 * `Splicewire\Beam\Accounts\Data\TokenData`).
 *
 * ## Why this is a SECOND shape and not a nullable field on {@see HookData}
 *
 * `HookData` is the resource's `data:` slot, which means it is what index, show, the scoped
 * projection AND the operator realm all project — the four of them share one class by construction.
 * A `?string $secret` there would be a field four surfaces had to remember to strip, and "remember
 * to strip" is not a security boundary. The secret is instead absent from the projection entirely
 * and present only here, on a shape only the create endpoint can return.
 *
 * That is also why create is hand-rolled REST beside the particle resource rather than the
 * resource's own `store`: the generic controller returns the `data:` projection, and there is no
 * slot in the particle contract for "the create response is a different shape than the read one".
 * Frame's generic create has the identical hole, which is exactly what `TokenData`'s docblock says
 * ("Frame's generic create has no notion of a create-response carrying a display-once secret").
 */
#[TypeScript]
class CreatedHookData extends BeamData
{
    public function __construct(
        #[Description('The hook, in the same shape every subsequent read projects it.')]
        public HookData $hook,

        #[Description('The HMAC signing secret, in the clear, for the only time it is ever transmitted. Store it now — every later read projects `secretPreview` and nothing more.')]
        public string $secret,

        #[Description('Whether a `hooks.ping` verification delivery was queued for this endpoint. False when the host disabled outbound delivery.')]
        public bool $pinged,
    ) {}

    public static function forHook(Hook $hook, string $secret, bool $pinged): self
    {
        return new self(
            hook: HookData::project($hook),
            secret: $secret,
            pinged: $pinged,
        );
    }
}
