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
 * The resource declares this shape as `createResultData:` for its Frame create handler. Generic
 * Particle REST still projects `data:`; it does not claim this result shape.
 */
#[TypeScript]
class CreatedHookData extends BeamData
{
    public function __construct(
        #[Description('The hook, in the same shape every subsequent read projects it.')]
        public HookData $hook,

        #[Description('The HMAC signing secret, in the clear, for the only time it is ever transmitted. Store it now — every later read projects `secretPreview` and nothing more.')]
        public string $secret,

        #[Description('Whether a `hooks.ping` verification delivery was queued for this endpoint. False when paused or when queueing the verification delivery fails.')]
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
