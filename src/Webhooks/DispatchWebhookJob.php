<?php

namespace Splicewire\Beam\Webhooks;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Rushing\RequestLogs\Services\RequestLogTracker;
use Splicewire\Beam\Models\Hook;

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

        $this->correlate();

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

        $this->recordSuccess();
    }

    /**
     * Every retry is exhausted — THIS is where the health counter moves, not `handle()`.
     *
     * `consecutive_failures` counts failed DELIVERIES, not failed attempts. Incrementing per attempt
     * would auto-disable a hook inside a single delivery's own retry ladder (five tries against a
     * threshold of five), which would turn one slow afternoon at the receiver into a subscription the
     * owner has to go and un-break by hand. The threshold's own docblock says what it means — "tries
     * exhausted five times over" — and only `failed()` can honour that reading.
     */
    public function failed(?\Throwable $exception = null): void
    {
        $hook = $this->hook();

        if ($hook === null) {
            return;
        }

        // The correlation id, not the request-log row's primary key. The row may not be flushed yet
        // (and on a host without `rushing/laravel-request-logs` it never will be), so the only value
        // available at this moment is the one this job CHOSE — the delivery uuid it sent as
        // `X-Request-Id`, which is `request_logs.request_id`. That is the column's real referent, and
        // it is also the value the receiver quotes back, which is what a support conversation starts
        // from.
        if ($hook->recordFailure($this->delivery->deliveryId())) {
            $this->announce($hook, 'hooks.disabled', [
                'hookId' => (string) $hook->id,
                'endpoint' => $hook->endpoint,
                'consecutiveFailures' => (int) $hook->consecutive_failures,
            ]);
        }
    }

    /**
     * A 2xx landed. Zero the failure counter, and — on the verification round trip specifically —
     * stamp `verified_at` and say so.
     *
     * The `hooks.ping` branch is what makes `verified_at` mean anything: 12 §5 defines verification as
     * the receiver ANSWERING, and the only party that observes the answer is this job.
     */
    protected function recordSuccess(): void
    {
        $hook = $this->hook();

        if ($hook === null) {
            return;
        }

        $hook->recordSuccess();

        if ($this->delivery->event !== 'hooks.ping' || $hook->verified_at !== null) {
            return;
        }

        $hook->verified_at = now();
        $hook->save();

        $this->announce($hook, 'hooks.verified', [
            'hookId' => (string) $hook->id,
            'endpoint' => $hook->endpoint,
        ]);
    }

    /**
     * Fan a hook-lifecycle event out to the OTHER subscriptions.
     *
     * Guarded because it is a courtesy, not the job's contract: a failure to tell anyone that a hook
     * disabled must not mask the disabling itself, and this runs from `failed()`, where throwing
     * would be re-entering the failure path from inside it.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function announce(Hook $hook, string $event, array $payload): void
    {
        try {
            app(HookEmitter::class)->emit($event, $payload, $hook);
        } catch (\Throwable $e) {
            Log::warning('Beam could not announce a hook lifecycle event.', [
                'hook' => $hook->id,
                'event' => $event,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /** The subscription this delivery belongs to, or null for a caller-supplied callback. */
    protected function hook(): ?Hook
    {
        if ($this->delivery->hookId === null) {
            return null;
        }

        return Hook::query()->find($this->delivery->hookId);
    }

    /**
     * Register the hook against this delivery's correlation id, so `GET /hooks/{hook}/deliveries` has
     * a join to read.
     *
     * `GuzzleRequestLogMiddleware` ADOPTS an `X-Request-Id` that is already on the outgoing request
     * rather than minting its own, which is what lets the delivery uuid become `request_logs.
     * request_id` — one identifier in the envelope, in `Idempotency-Key`, in `X-Beam-Delivery`, and in
     * the log row.
     *
     * Every line of this is optional-dependency-guarded. `rushing/laravel-request-logs` is
     * deliberately NOT a beam-core require (ticket 37), so a bare host must deliver webhooks perfectly
     * well and simply have nothing to show in the deliveries list.
     */
    protected function correlate(): void
    {
        if ($this->delivery->hookId === null || ! class_exists(RequestLogTracker::class)) {
            return;
        }

        try {
            app(RequestLogTracker::class)->addModel(
                $this->delivery->deliveryId(),
                (new Hook)->getMorphClass(),
                (string) $this->delivery->hookId,
            );
        } catch (\Throwable) {
            // No tracker bound, or the container has no idea what that is. The delivery is the point.
        }
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
            // The correlation header the request-log middleware ADOPTS rather than replaces — see
            // {@see correlate()}. Named from config because the host owns that spelling; on a host
            // without the package it is an inert, conventional header the receiver may ignore.
            (string) config('request-logs.correlation.header_name', 'X-Request-Id') => $this->delivery->deliveryId(),
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
