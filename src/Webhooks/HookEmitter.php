<?php

namespace Splicewire\Beam\Webhooks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Splicewire\Beam\Entitlements\EntitlementGate;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Models\Hook;

/**
 * The emission half of the hook surface (api-surface-coherence ticket 38): given an event name and a
 * payload, find the subscriptions that want it and hand each a signed {@see WebhookDelivery}.
 *
 * ## Emission never throws, and that rule has already been paid for twice on this map
 *
 * Ticket 13 §5: an emission-time derivation failure WITHHOLDS the delivery and REPORTS — it must
 * never abort the Laravel event. Ticket 91: a check whose answer depends on which host loaded the
 * provider must not be fatal, which is why the catalog's prefix check became advisory after it took
 * `~/Herd/tower` off the air. Both rules point the same way here, so {@see emit()} catches per-hook
 * and keeps going: one bad subscription must not cost the other nine their delivery, and none of
 * them may cost the domain event its completion.
 *
 * The event NAME is likewise not validated against the catalog at emission
 * ({@see EventTypeRegistry}'s docblock: "emission validates nothing"). An unregistered name is a
 * producer whose delivery finds no subscribers, which is a catalog miss the doctor reports — not a
 * 500 in the middle of somebody's job.
 *
 * ## The two authorization planes, and which one is asked here
 *
 * Only the FEATURE plane (ticket 13 §1–§2). The ACTION plane was asked ONCE, at subscribe time,
 * where a principal existed; 12 §7 removed the principal from this side, and that limitation is on
 * the record — a revoked ROLE is not caught here, a lapsed PLAN is.
 *
 * The feature requirement is the `entitlement_keys` snapshot taken off the `entitlement:*` middleware
 * on the route the subscribe request arrived through (13 §6). It is a SNAPSHOT because it is not
 * re-derivable: no request, no route. A bare beam host snapshots an EMPTY set, which passes trivially
 * without the gate ever being consulted — which is 13 §8's "null implementation allows", satisfied by
 * the empty set rather than by a second port, since beam core already ships
 * {@see EntitlementGate} and its null resolver DENIES every key rather than allowing it.
 *
 * A failure here PAUSES and does not fail (13 §4): `paused_at` is set, `consecutive_failures` is not
 * touched, no delivery and no `request_logs` row is written because nothing was sent, and it counts
 * for nothing toward auto-disable. A lapsed plan must never route a user to `op/reset`, which cannot
 * fix it.
 */
class HookEmitter
{
    public function __construct(
        private WebhookDispatcher $dispatcher,
        private EntitlementGate $entitlements,
    ) {}

    /**
     * Fan `$eventName` out to every subscription that wants it. Returns the hooks actually dispatched
     * to — the paused, the withheld and the unsubscribed are all absent, and the caller is not
     * expected to care which.
     *
     * @param  array<string, mixed>  $payload
     * @return list<Hook>
     */
    public function emit(string $eventName, array $payload, ?Model $subject = null, ?string $occurredAt = null): array
    {
        $occurredAt ??= now()->toIso8601String();
        $delivered = [];

        foreach (Hook::query()->subscribedTo($eventName, $subject)->get() as $hook) {
            try {
                if (! $this->entitled($hook)) {
                    $this->pause($hook);

                    continue;
                }

                $this->dispatcher->send($this->deliveryFor($hook, $eventName, $payload, $occurredAt));

                $delivered[] = $hook;
            } catch (\Throwable $e) {
                // Withhold and report. See the class docblock — the domain event completes regardless.
                Log::warning('Beam hook emission withheld a delivery.', [
                    'hook' => $hook->id,
                    'event' => $eventName,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return $delivered;
    }

    /**
     * The verification round trip (12 §5): a `hooks.ping` POSTed at create. A 2xx within the window
     * sets `verified_at` — done by whoever observes the response, not asserted here.
     *
     * `hooks.ping` is deliberately NOT a catalog entry: it is not subscribable, because subscribing
     * to your own verification is not a thing anyone wants and listing it would put a name in
     * `GET /hooks/events` that no `events` array may legally contain.
     */
    public function ping(Hook $hook): void
    {
        $this->dispatcher->send($this->deliveryFor($hook, 'hooks.ping', [
            'hookId' => (string) $hook->id,
            'endpoint' => $hook->endpoint,
        ], now()->toIso8601String()));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function deliveryFor(Hook $hook, string $eventName, array $payload, string $occurredAt): WebhookDelivery
    {
        return new WebhookDelivery(
            event: $eventName,
            payload: $payload,
            endpoint: $hook->endpoint,
            token: $hook->token,
            secret: $hook->secret,
            hookId: (string) $hook->id,
            occurredAt: $occurredAt,
        );
    }

    /**
     * Every snapshotted entitlement key must still pass. An empty snapshot passes trivially and never
     * consults the gate — see the class docblock.
     */
    public function entitled(Hook $hook): bool
    {
        $keys = array_values(array_filter((array) ($hook->entitlement_keys ?? [])));

        foreach ($keys as $key) {
            if (! $this->entitlements->allows((string) $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pause, idempotently. Already-paused stays paused with its original timestamp — re-stamping it
     * on every emission would make "when did this lapse" unanswerable.
     */
    private function pause(Hook $hook): void
    {
        if ($hook->paused_at !== null) {
            return;
        }

        $hook->paused_at = now();
        $hook->save();
    }
}
