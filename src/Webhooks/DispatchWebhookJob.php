<?php

namespace Splicewire\Beam\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Delivers one WebhookDelivery. Retriable + idempotent: each attempt carries the
 * same Idempotency-Key so a receiver can dedupe a redelivery. Throws on a non-2xx
 * response so the queue retries with backoff.
 *
 * There is deliberately NO audit row written here. The former
 * `Splicewire\Tower\Models\WebhookDeliveryLog` / `webhook_deliveries` pair was a
 * wiring gap, not a design (api-surface-coherence 11, built in 37): a host with
 * `rushing/laravel-request-logs` bound logs this call like any other outbound HTTP
 * call, and one that has not bound it logs nothing — which is the correct posture
 * for a beam-core edge that must carry no new dependency. The transport half of the
 * record lives in `request_logs`; the domain half (`event`, `idempotencyKey`,
 * `attempt`) rides the ENVELOPE, which a receiver and a request-log row can be
 * joined on because the delivery uuid appears in both.
 *
 * ## The body is composed once, and signed over exactly what is sent
 *
 * `Http::post($url, $array)` re-encodes the array itself, so a signature computed over a separately
 * encoded string would be signing bytes that never went on the wire — the classic HMAC-webhook bug,
 * and unfalsifiable in a test that verifies with the same encoder. `withBody()` posts the string this
 * method signed, verbatim (api-surface-coherence 38).
 *
 * ## The queue-flush trap this job sits squarely in
 *
 * `rushing/laravel-request-logs`' collector flushes on `app()->terminating()`, and a queue worker
 * never terminates between jobs — measured on api-surface-coherence 37, which is why the outbound
 * call this job makes is logged by the worker's own flush and not by anything here. Nothing in this
 * class registers a terminating callback, and nothing should: doing so from inside a job is what
 * produced 37's flush recursion.
 */
class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public WebhookDelivery $delivery,
    ) {
        // Mint the delivery uuid HERE, at construction, before the job is serialized onto the queue.
        // Deferring it to handle() would mint a new one on every retry and silently break the
        // receiver-side dedupe the Idempotency-Key exists for.
        $this->delivery->deliveryId();
    }

    public function tries(): int
    {
        return (int) config('webhooks.outbound.tries', 5);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('webhooks.outbound.backoff', [10, 30, 60, 120]);
    }

    /**
     * TLS is verified in production and skipped elsewhere (self-signed *.test).
     * Env-guarded, not hard-coded off — that was the latent bug in the old job.
     */
    public function verifiesTls(): bool
    {
        return app()->isProduction();
    }

    public function handle(): void
    {
        $body = HookDeliveryEnvelope::forDelivery($this->delivery)->toJson();

        $request = Http::acceptJson()
            ->withHeaders($this->headers($body))
            ->timeout((int) config('webhooks.outbound.timeout', 30));

        if (! $this->verifiesTls()) {
            $request = $request->withoutVerifying();
        }

        if (! empty($this->delivery->token)) {
            $request = $request->withToken($this->delivery->token);
        }

        $response = null;
        $error = null;

        try {
            $response = $request->withBody($body, 'application/json')->post($this->delivery->endpoint);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        // Surface failure so the queue retries with backoff.
        if ($error !== null) {
            throw new \RuntimeException("Webhook delivery to {$this->delivery->endpoint} failed: {$error}");
        }

        $response->throw();
    }

    /**
     * The delivery headers. `X-Beam-*` and NOT `X-Splicewire-*` — free-tier package, paid vendor name
     * (ticket 12 §5) — and the prefix is host-overridable through `webhooks.outbound.header_prefix`.
     *
     * `Idempotency-Key` keeps its standard spelling rather than joining the prefixed set: it is a
     * cross-vendor convention receivers already implement, and renaming it would make this edge
     * gratuitously special.
     *
     * @return array<string, string>
     */
    protected function headers(string $body): array
    {
        $headers = [
            'Idempotency-Key' => $this->delivery->deliveryId(),
            HookSignature::header('delivery') => $this->delivery->deliveryId(),
            HookSignature::header('event') => $this->delivery->event,
        ];

        if ($this->delivery->hookId !== null) {
            $headers[HookSignature::header('hook')] = $this->delivery->hookId;
        }

        if ($this->delivery->signed()) {
            $headers[HookSignature::header('signature')] = HookSignature::sign($body, $this->delivery->secret);
        }

        return $headers;
    }
}
