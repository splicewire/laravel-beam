<?php

namespace Splicewire\Beam\Localization;

/**
 * Who a delivery is for, and the workspace it concerns (localization 36; ruling 03 §3).
 *
 * The no-request order reads the recipient's saved preference, then the DELIVERY CONTEXT's tenant default, then the
 * brand and the application default. A message about one workspace (an invitation to it) must take that workspace's
 * default, not whatever tenant the sender happens to be in, so the workspace travels beside the recipient.
 *
 * {@see LocaleResolver::forDelivery()} passes it to every slot as `$recipient`. A slot that knows nothing of it reads
 * `notifiable`; the person slot reads the recipient from it, and the tenant slot the workspace.
 *
 * - `notifiable`: the recipient (a notifiable model, an anonymous mail route, or null).
 * - `workspace`: the workspace the delivery concerns (for example the invitation's team or tenant), or null for the
 *   bootstrapped delivery context.
 * - `realm`: the realm the delivery renders in, explicitly (ruling 03 §3: "carry an explicit realm or null/base"). Null
 *   is the base rung. A delivery NEVER inherits the sender's realm (range review B12-1).
 */
final readonly class DeliveryRecipient
{
    public function __construct(
        public mixed $notifiable,
        public mixed $workspace = null,
        public ?string $realm = null,
    ) {}

    /** The bare recipient, whether or not it was wrapped. */
    public static function notifiableOf(mixed $recipient): mixed
    {
        return $recipient instanceof self ? $recipient->notifiable : $recipient;
    }
}
