<?php

namespace Splicewire\Beam\Webhooks;

/**
 * The generic OUTBOUND edge. Domain-event subscribers build a WebhookDelivery
 * and hand it here; delivery + retry happen off the request path in
 * DispatchWebhookJob.
 *
 * Rehomed from `Splicewire\Tower\Webhooks` (api-surface-coherence 37). Nothing in
 * this trio was tower-tier — it is `Http::post` plus retry config — and beam core
 * is where the hook surface (ticket 38) lands, so the edge belongs under it.
 */
class WebhookDispatcher
{
    public function send(WebhookDelivery $delivery): void
    {
        DispatchWebhookJob::dispatch($delivery);
    }
}
