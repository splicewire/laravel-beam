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
 * `attempt`) has no home until the emission record exists (ticket 12) and is not
 * shoehorned into a transport table.
 */
class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public WebhookDelivery $delivery,
    ) {}

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
        $request = Http::acceptJson()
            ->asJson()
            ->timeout((int) config('webhooks.outbound.timeout', 30))
            ->withHeaders(array_filter([
                'Idempotency-Key' => $this->delivery->idempotencyKey,
            ]));

        if (! $this->verifiesTls()) {
            $request = $request->withoutVerifying();
        }

        if (! empty($this->delivery->token)) {
            $request = $request->withToken($this->delivery->token);
        }

        $response = null;
        $error = null;

        try {
            $response = $request->post($this->delivery->endpoint, [
                'event' => $this->delivery->event,
                ...$this->delivery->payload,
            ]);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        // Surface failure so the queue retries with backoff.
        if ($error !== null) {
            throw new \RuntimeException("Webhook delivery to {$this->delivery->endpoint} failed: {$error}");
        }

        $response->throw();
    }
}
