<?php

namespace Splicewire\Beam\Webhooks\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Data\HookInputData;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Webhooks\Data\CreatedHookData;
use Splicewire\Beam\Webhooks\HookEmitter;
use Throwable;

/**
 * `POST /hooks` and `POST /{resource}/hooks` — subscribe, hand-rolled beside the particle resource
 * (api-surface-coherence ticket 38, decided by 12 §1 and 13 §1–§8).
 *
 * ## Why create is not the resource's own `store`
 *
 * A create response that carries a display-once secret is a DIFFERENT shape than the read
 * projection, and the particle contract has one `data:` slot for both. `TokenData`'s docblock in
 * `splicewire/laravel-beam-accounts` records the identical finding for Frame's generic create and
 * takes the identical exit — "the reveal-once create stays a host REST survivor". This follows that
 * precedent rather than widening the particle contract for one endpoint.
 *
 * ## The ACTION plane is asked exactly here, and nowhere else, ever
 *
 * Ticket 13 §1–§2 splits the gate across two planes and asks them in two places. This is the only
 * place a PRINCIPAL exists, so this is where the action plane is asked — `view` against the subject
 * when the hook carries one, `viewAny` against the subscribed resource's model class otherwise, with
 * the permission NAME re-derived from the particle stamp every time rather than stored (13 §6, so
 * `hooks` never becomes a fourth stored-name case for ticket 16 to rot).
 *
 * The feature plane is re-checked at EMISSION, off the `entitlement_keys` snapshot this method takes.
 * The snapshot is taken here because it is not re-derivable later: it is read off the `entitlement:*`
 * middleware on the route this request arrived through, and at emission there is no request and no
 * route. A bare beam host mounts `/hooks` without an entitlement middleware, snapshots the empty set,
 * and passes the feature plane trivially — 13 §8's "null implementation allows", satisfied by the
 * empty set rather than by a second port.
 *
 * ## Every event name is checked against the CATALOG, and the failure is a 422 not a 500
 *
 * The catalog (ticket 40) is the vocabulary; a subscription naming something outside it is a
 * subscription that can never fire. That is a caller mistake, so it is a validation error against the
 * `events` field — with the legal names in the message, because the alternative is a client guessing.
 *
 * What it is NOT is a boot-time or host-dependent throw. Ticket 91's rule — a check whose answer
 * depends on the host must not be fatal — is why `GET /hooks/events` answers empty for an unknown
 * resource rather than 404ing, and it is why this validation is scoped to the one request that made
 * the claim.
 *
 * @group Platform
 */
class HookSubscriptionController extends Controller
{
    public function __construct(
        private EventTypeRegistry $catalog,
        private ParticleResourceRegistry $resources,
        private HookEmitter $emitter,
    ) {}

    /**
     * Subscribe to events
     *
     * Mints the hook's HMAC signing secret and returns it in the clear **once**. Every later read of
     * this hook projects a preview and nothing more, so a caller that does not store the secret from
     * this response cannot recover it — only replace it by re-subscribing.
     *
     * A `hooks.ping` delivery is queued to the endpoint; answering it with a 2xx is what sets
     * `verifiedAt`.
     */
    #[RequestFromData(HookInputData::class)]
    #[ResponseFromData(CreatedHookData::class, status: 201, description: 'The subscription, plus its signing secret — for the only time it is transmitted.')]
    public function store(Request $request)
    {
        $input = HookInputData::validateAndCreate($request);

        $events = $this->requireEvents($input, $this->scopedResource($request));
        $subject = $this->resolveSubject($input);

        $this->authorizeSubscription($events, $subject);

        $secret = Hook::mintSecret();

        $hook = new Hook($input->toModelAttributes());
        $hook->events = $events;
        $hook->secret = $secret;
        $hook->entitlement_keys = $this->entitlementSnapshot($request);
        $this->stampOwner($hook, $request);
        $hook->save();

        return ResponseBody::from([
            'data' => CreatedHookData::forHook($hook, $secret, $this->ping($hook)),
        ])->created();
    }

    // ── The events array ────────────────────────────────────────────────────────────────────────

    /**
     * The subscribed event names, validated against the catalog — and, at the scoped exposure,
     * against the prefix the URL already committed to.
     *
     * 12 §1 calls the scoped exposure "prefix-filtered, prefix-prefilling": arriving at
     * `POST /compositions/hooks` with no `events` is not an error, it is a subscription to every
     * `compositions.*` name there is. Arriving there with an event from another resource IS an error,
     * because the URL and the body would be making two different claims and silently honouring one of
     * them is how a subscription ends up somewhere nobody looked for it.
     *
     * @return list<string>
     */
    protected function requireEvents(HookInputData $input, ?string $resource): array
    {
        $events = array_values(array_unique(array_map('strval', $input->events ?? [])));

        if ($events === [] && $resource !== null) {
            $events = array_map(fn ($type) => $type->name, $this->catalog->withPrefix($resource));
        }

        if ($events === []) {
            throw ValidationException::withMessages([
                'events' => 'Name at least one event to subscribe to. GET hooks/events lists them.',
            ]);
        }

        $unknown = array_values(array_filter($events, fn (string $name) => ! $this->catalog->has($name)));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'events' => 'Not in the event catalog: '.implode(', ', $unknown)
                    .'. Legal names: '.(implode(', ', $this->catalog->names()) ?: '(this host publishes none)').'.',
            ]);
        }

        if ($resource !== null) {
            $foreign = array_values(array_filter(
                $events,
                fn (string $name) => ! str_starts_with($name.'.', $resource.'.'),
            ));

            if ($foreign !== []) {
                throw ValidationException::withMessages([
                    'events' => "This exposure is scoped to `{$resource}`; subscribe to "
                        .implode(', ', $foreign).' at the root /hooks exposure instead.',
                ]);
            }
        }

        return array_values($events);
    }

    /** The `{resource}` path segment at the scoped exposure, or null at the root one. */
    protected function scopedResource(Request $request): ?string
    {
        $resource = $request->route()?->parameter('resource');

        return is_string($resource) && $resource !== '' ? $resource : null;
    }

    // ── The action plane (13 §1–§2) ─────────────────────────────────────────────────────────────

    /**
     * Ask the action plane ONCE, here, where a principal exists.
     *
     * With a subject: `view` on that record. Without: `viewAny` on the model class behind EVERY
     * resource key the subscription spans — all of them, not the first, because an events array
     * naming two resources is two claims and passing one of them is not passing.
     *
     * A resource key beam cannot resolve to a backing model is NOT silently allowed and NOT fatal: it
     * cannot be in `$events` at all, because {@see requireEvents()} already refused any name the
     * catalog does not hold, and the catalog's own prefix audit reports a registered name whose
     * resource is absent. Reaching the `continue` below therefore means the host registered an event
     * for a resource it has since dropped — an advisory finding elsewhere, and here an event nothing
     * can produce.
     *
     * @param  list<string>  $events
     *
     * @throws AuthorizationException
     */
    protected function authorizeSubscription(array $events, ?Model $subject): void
    {
        if ($subject !== null) {
            Gate::authorize('view', $subject);

            return;
        }

        foreach ($this->resourceKeysOf($events) as $key) {
            $backing = $this->backingModel($key);

            if ($backing === null) {
                continue;
            }

            Gate::authorize('viewAny', $backing);
        }
    }

    /**
     * @param  list<string>  $events
     * @return list<string>
     */
    protected function resourceKeysOf(array $events): array
    {
        $hook = new Hook;
        $hook->events = $events;

        return $hook->resourceKeys();
    }

    /** @return class-string|null */
    protected function backingModel(string $resourceKey): ?string
    {
        if (! $this->resources->has($resourceKey)) {
            return null;
        }

        try {
            $backing = $this->resources->get($resourceKey)->backing;
        } catch (Throwable) {
            return null;
        }

        return class_exists($backing) ? $backing : null;
    }

    /**
     * Resolve the optional subject morph, refusing a half-supplied pair.
     *
     * `subject_type` without `subject_id` (or the reverse) is not "no subject" — it is a caller who
     * believes they scoped their subscription and did not. Honouring it as a firehose over the whole
     * resource is the failure the ticket's subject-deletion rule exists to prevent, arriving through
     * the front door instead.
     */
    protected function resolveSubject(HookInputData $input): ?Model
    {
        if ($input->subject_type === null && $input->subject_id === null) {
            return null;
        }

        if ($input->subject_type === null || $input->subject_id === null) {
            throw ValidationException::withMessages([
                'subject_type' => 'Supply both subject_type and subject_id, or neither.',
            ]);
        }

        $class = Relation::getMorphedModel($input->subject_type) ?? $input->subject_type;

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw ValidationException::withMessages([
                'subject_type' => "`{$input->subject_type}` is not a model this host knows.",
            ]);
        }

        $subject = $class::query()->find($input->subject_id);

        if ($subject === null) {
            throw ValidationException::withMessages([
                'subject_id' => 'No such record to subscribe against.',
            ]);
        }

        return $subject;
    }

    // ── The feature plane's snapshot (13 §6) ────────────────────────────────────────────────────

    /**
     * The `entitlement:*` keys on the route this request arrived through.
     *
     * Read from the route rather than from the container: the requirement is "what did the caller
     * have to hold to reach this door", and only the route knows that. Two exposures of the same
     * resource may sit behind different middleware, and a hook created through the gated one must
     * stay gated even though the record is identical.
     *
     * @return list<string>
     */
    protected function entitlementSnapshot(Request $request): array
    {
        $keys = [];

        foreach ((array) ($request->route()?->gatherMiddleware() ?? []) as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'entitlement:')) {
                continue;
            }

            foreach (explode(',', substr($middleware, strlen('entitlement:'))) as $key) {
                $key = trim($key);

                if ($key !== '') {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * Stamp the owner morph — AUDIT ONLY (12 §7). Nothing scopes a read by it, deliberately: see
     * {@see HookData::scope()} for why a scope here would be the worse bug.
     */
    protected function stampOwner(Hook $hook, Request $request): void
    {
        $actor = $request->user();

        if ($actor instanceof Model) {
            $hook->owner_type = $actor->getMorphClass();
            $hook->owner_id = $actor->getKey();
        }
    }

    /**
     * Queue the verification round trip. It must not be able to fail the create.
     *
     * A subscription that exists but is unverified is a recoverable state with a visible column; a
     * 500 on create because the queue connection hiccuped loses the secret forever, because the
     * secret is only ever transmitted in the response this method sits inside.
     */
    protected function ping(Hook $hook): bool
    {
        try {
            $this->emitter->ping($hook);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
