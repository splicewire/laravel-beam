<?php

namespace Splicewire\Beam\Webhooks;

use Spatie\LaravelData\Data;

/**
 * The wire body of one outbound delivery (api-surface-coherence ticket 38).
 *
 * ```json
 * { "id": "…uuid…", "event": "tenants.provisioned", "occurredAt": "2026-08-26T04:00:00+00:00",
 *   "data": { … }, "hook": { "id": "…uuid…" } }
 * ```
 *
 * ## Why an envelope, when the previous shape was a flat merge
 *
 * The shipping edge posted `['event' => …, ...$payload]` — the event name spread into the SAME
 * object as the domain payload. Three consequences, all of them real: a payload with its own `event`
 * key silently won; a receiver had no stable place to read a delivery id from, so the three
 * producers each hand-assembled an idempotency key string of their own; and there was nowhere to put
 * a field like `occurredAt` that is about the delivery rather than about the subject. Nesting the
 * domain body under `data` fixes all three at once and costs one level of indirection at the
 * receiver.
 *
 * `id` is the delivery uuid and it is the SAME value across every retry of that delivery — echoed in
 * `X-Beam-Delivery` and sent as `Idempotency-Key`. That is what makes a receiver's dedupe correct
 * rather than merely plausible.
 *
 * `hook: {id}` is an object rather than a bare `hookId` so the slot has room to grow (a hook's label,
 * its subject) without a second breaking change to a shape receivers parse.
 *
 * Keys are camelCase per ticket 22, declared as camelCase property names rather than through a
 * mapper: this class exists to fix a wire shape, so the shape should be readable in the source.
 */
class HookDeliveryEnvelope extends Data
{
    /**
     * @param  string  $id  the delivery uuid — stable across retries, the idempotency key
     * @param  string  $event  the catalog event name, plural-verbatim (`compositions.render.completed`)
     * @param  string  $occurredAt  ISO-8601 with offset, when the domain event happened (NOT when we sent)
     * @param  array<string, mixed>  $data  the domain payload, unflattened
     * @param  array{id: string}|null  $hook  the subscription this delivery satisfies, when there is one
     */
    public function __construct(
        public string $id,
        public string $event,
        public string $occurredAt,
        public array $data,
        public ?array $hook = null,
    ) {}

    public static function forDelivery(WebhookDelivery $delivery): static
    {
        return new static(
            id: $delivery->deliveryId(),
            event: $delivery->event,
            occurredAt: $delivery->occurredAt ?? now()->toIso8601String(),
            data: $delivery->payload,
            hook: $delivery->hookId === null ? null : ['id' => $delivery->hookId],
        );
    }

    /**
     * The exact bytes that go on the wire AND get signed. One method, called once, so the signature
     * can never be computed over a different encoding than the body — the classic HMAC-webhook bug.
     */
    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | $options);
    }
}
