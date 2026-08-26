<?php

namespace Splicewire\Beam\Webhooks;

use Spatie\LaravelData\Data;

/**
 * A single OUTBOUND webhook to deliver: what event, to whom, with what auth.
 *
 * The seam a domain-event subscriber builds and hands to the WebhookDispatcher.
 * If a future consumer needs HMAC signing instead of a bearer token, that policy
 * slots in behind this VO — callers don't change.
 *
 * Direction matters when reading this namespace: `Splicewire\Beam\Webhooks\*` is
 * the outbound edge only. The INBOUND spine (receipts, envelopes, verification
 * profiles) is `Splicewire\Satellite\Webhooks\*`, a tier up — beam never reaches
 * for it.
 */
class WebhookDelivery extends Data
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $event,
        public array $payload,
        public string $endpoint,
        public ?string $token = null,
        public ?string $idempotencyKey = null,
    ) {}
}
