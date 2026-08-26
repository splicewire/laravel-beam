<?php

namespace Splicewire\Beam\Webhooks;

use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * A single OUTBOUND webhook to deliver: what event, to whom, with what auth.
 *
 * The seam a domain-event subscriber builds and hands to the WebhookDispatcher.
 *
 * Direction matters when reading this namespace: `Splicewire\Beam\Webhooks\*` is
 * the outbound edge only. The INBOUND spine (receipts, envelopes, verification
 * profiles) is `Splicewire\Satellite\Webhooks\*`, a tier up — beam never reaches
 * for it.
 *
 * ## `secret` is the seam this docblock used to only promise
 *
 * The previous revision said "if a future consumer needs HMAC signing instead of a bearer token,
 * that policy slots in behind this VO — callers don't change." api-surface-coherence ticket 38 built
 * it, and the promise held: `secret` sits alongside `token`, both nullable, and neither replaces the
 * other. A delivery with a secret is signed ({@see HookSignature}); a delivery with a token carries
 * a bearer; a delivery with both carries both, which is what a receiver migrating from one to the
 * other actually needs.
 *
 * A delivery with NEITHER is legal and unsigned — that is the pre-hook caller-supplied-callback case
 * ticket 37 rehomed, and it keeps working unchanged.
 */
class WebhookDelivery extends Data
{
    /**
     * @param  array<string, mixed>  $payload  the domain body; ships under `data` in the envelope
     * @param  string|null  $token  caller-supplied bearer, sent as `Authorization: Bearer …`
     * @param  string|null  $secret  the HMAC key this delivery is signed with, off the hook row
     * @param  string|null  $hookId  the subscription id, when this delivery satisfies a stored hook
     * @param  string|null  $occurredAt  ISO-8601; defaults to send time, which is only right for a
     *                                   delivery built in the same tick as the event it reports
     * @param  string|null  $idempotencyKey  the delivery uuid; minted by {@see deliveryId()} when null
     */
    public function __construct(
        public string $event,
        public array $payload,
        public string $endpoint,
        public ?string $token = null,
        public ?string $idempotencyKey = null,
        public ?string $secret = null,
        public ?string $hookId = null,
        public ?string $occurredAt = null,
    ) {}

    /**
     * The delivery uuid — `id` in the envelope, `X-Beam-Delivery`, and `Idempotency-Key`, one value in
     * all three places.
     *
     * Minted ONCE and written back onto the VO, because the job is serialized between retries and a
     * freshly-minted id per attempt would defeat the receiver-side dedupe this whole scheme exists to
     * enable. A caller may still supply its own; ticket 38 retired the three hand-assembled key
     * strings that used to, but the slot stays for a caller correlating against a foreign system.
     */
    public function deliveryId(): string
    {
        return $this->idempotencyKey ??= (string) Str::uuid();
    }

    public function signed(): bool
    {
        return $this->secret !== null && $this->secret !== '';
    }
}
