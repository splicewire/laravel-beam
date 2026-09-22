<?php

namespace Splicewire\Beam\Webhooks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Data\HookInputData;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Webhooks\Data\CreatedHookData;
use Throwable;

/** Creates a vetted subscription and reveals its minted signing secret exactly once. */
class CreateHookSubscription
{
    public function __construct(
        private EventTypeRegistry $catalog,
        private HookSubscriptionReach $reach,
        private HookEmitter $emitter,
    ) {}

    public function create(HookInputData $input, Request $request): CreatedHookData
    {
        if ($input->endpoint === null || trim($input->endpoint) === '') {
            throw ValidationException::withMessages(['endpoint' => 'An endpoint is required to subscribe.']);
        }

        $events = array_values(array_unique($input->events ?? []));
        if ($events === []) {
            throw ValidationException::withMessages(['events' => 'Name at least one event to subscribe to. GET hooks/events lists them.']);
        }

        $unknown = array_values(array_filter($events, fn (string $name) => ! $this->catalog->has($name)));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'events' => 'Not in the event catalog: '.implode(', ', $unknown)
                    .'. Legal names: '.(implode(', ', $this->catalog->names()) ?: '(this host publishes none)').'.',
            ]);
        }

        $subject = $this->reach->resolveSubject(
            $input->subject_type instanceof Optional ? null : $input->subject_type,
            $input->subject_id instanceof Optional ? null : $input->subject_id,
        );
        $this->reach->authorize($events, $subject);

        $secret = Hook::mintSecret();
        $hook = new Hook($input->toModelAttributes());
        $hook->events = $events;
        $hook->secret = $secret;
        $hook->entitlement_keys = $this->entitlementSnapshot($request);
        $this->stampOwner($hook, $request);
        $hook->save();

        return CreatedHookData::forHook($hook, $secret, $this->ping($hook));
    }

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
     *
     * ⚠️ The key is cast to STRING because `owner_id` is a string column, and it is a string column
     * because the principal's key type is the host's business, not beam's: the starters key `users`
     * bigint, the flagship keys tenant users uuid. Until 2026-09-12 the column was
     * `nullableMorphs()`' bigint and this line put a uuid in it — `SQLSTATE[22P02]` out of the
     * package's own primary write path, measured on the ux-demo-convergence G3 flagship fixture.
     * The cast is not what fixed that (the column widening is); it is here so the value written and
     * the value read back are the same shape on every driver, sqlite included, where an int key
     * would otherwise round-trip as an int out of a varchar column.
     */
    protected function stampOwner(Hook $hook, Request $request): void
    {
        $actor = $request->user();

        if ($actor instanceof Model) {
            $hook->owner_type = $actor->getMorphClass();
            $hook->owner_id = (string) $actor->getKey();
        }
    }

    /** Queue failure must not lose the only response that reveals the persisted secret. */
    private function ping(Hook $hook): bool
    {
        if (! $hook->deliverable()) {
            return false;
        }

        try {
            $this->emitter->ping($hook);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
