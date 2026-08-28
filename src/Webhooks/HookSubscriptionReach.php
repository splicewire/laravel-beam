<?php

namespace Splicewire\Beam\Webhooks;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Data\HookInputData;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Webhooks\Http\HookSubscriptionController;
use Throwable;

/**
 * The REACH check on a subscription — "may this principal receive these events, for this subject?" —
 * lifted out of {@see HookSubscriptionController} so BOTH write doors ask it
 * (particle-write-surface ticket 04).
 *
 * ## Why this class exists at all
 *
 * The check used to live entirely inside the hand-rolled `POST /hooks` controller, with exactly one
 * call site. The particle update path authorizes the **Hook row** (`update` on the model, through
 * `GateWriteGate`), which is a different question — and measured 2026-08-27 with the gate CLOSED
 * (explicit policies, no `Gate::before`), a `PUT /hooks/{id}` re-pointed a hook's
 * `subject_type`/`subject_id` at a record the actor could not `view`, answering **200** and
 * persisting the forged subject. A subscription is a *read grant with a push delivery attached*, so
 * re-pointing it is exactly as sensitive as creating it, and it was the one spelling nothing vetted.
 *
 * The repair is NOT "call the controller's method from the generic controller". It is this object,
 * asked from two places:
 *
 *   - {@see HookSubscriptionController::authorizeSubscription()} — create, unchanged in behaviour.
 *   - {@see HookData::prepare()} — the particle convention hook, which `ParticleController` runs on
 *     create AND update alike. `RankTreeData::prepare()` re-rooting `parent_id` on both paths is the
 *     estate's existing example of that seam carrying a rule the generic controller must not know.
 *
 * A `WriteStage` was the third candidate and is the wrong home: the write pipeline's stages are
 * cross-cutting by construction (authorize, validate, persist, emit), and a stage that special-cases
 * one resource key would put webhook vocabulary into the chain every write in the estate runs through.
 * The `prepare()` hook is per-resource by design and is where this rule belongs.
 *
 * ## What "changed" means, and why an unchanged subject is deliberately NOT re-vetted
 *
 * {@see vetWrite()} fires only when the write would MOVE the subscription — a different subject, or
 * (for a subjectless hook) a wider set of event names. Re-vetting an unchanged subject on every
 * `PATCH {"paused": true}` would turn an ordinary field edit into a second authorization boundary
 * that the create path never promised, and would 403 an actor who legitimately holds the hook after
 * their reach to its subject lapsed. The defect is the *re-point*, so the check is on the *delta*.
 */
class HookSubscriptionReach
{
    public function __construct(
        private EventTypeRegistry $catalog,
        private ParticleResourceRegistry $resources,
    ) {}

    /**
     * Ask the action plane (api-surface-coherence 13 §1–§2).
     *
     * With a subject: `view` on that record. Without: `viewAny` on the model class behind EVERY
     * resource key the subscription spans — all of them, not the first, because an events array
     * naming two resources is two claims and passing one of them is not passing.
     *
     * A resource key beam cannot resolve to a backing model is neither silently allowed nor fatal:
     * it means the host registered an event for a resource it has since dropped, which is an
     * advisory finding elsewhere and here an event nothing can produce.
     *
     * @param  list<string>  $events
     *
     * @throws AuthorizationException
     */
    public function authorize(array $events, ?Model $subject): void
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
     * Vet a particle write against the subscription the hook would END UP being.
     *
     * Called from {@see HookData::prepare()}, so it runs on the particle create and update paths
     * alike. It is a no-op for a body that moves neither the subject nor the event set — see the
     * class docblock for why the check is on the delta rather than on every write.
     *
     * @throws AuthorizationException a re-point the actor cannot reach
     * @throws ValidationException a half-supplied morph, an unresolvable subject, or an event name
     *                             outside the catalog
     */
    public function vetWrite(Hook $hook, HookInputData $input, mixed $actor = null): void
    {
        $currentType = $hook->subject_type;
        $currentId = $hook->subject_id === null ? null : (string) $hook->subject_id;

        // ⚠️ `Optional`-aware on purpose, and this is the load-bearing line of the method. These two
        // fields are three-state: ABSENT (the `Optional` sentinel — leave the subject alone), an
        // explicit NULL (clear it, widening the hook to the whole resource), or a value (re-point).
        // A `??` here would collapse the first two, so an explicit null would read as "not supplied",
        // fall back to the current subject, skip the re-vet — and `HookInputData::toModelAttributes()`
        // would then write the null anyway. That is the ticket-04 gap in a new spelling, which is why
        // particle-write-surface ticket 01 held the `Optional` conversion back until this line existed.
        $type = $input->subject_type instanceof Optional ? $currentType : $input->subject_type;
        $id = $input->subject_id instanceof Optional
            ? $currentId
            : ($input->subject_id === null ? null : (string) $input->subject_id);

        $currentEvents = array_values(array_map('strval', (array) ($hook->events ?? [])));
        $events = $input->events !== null
            ? array_values(array_unique(array_map('strval', $input->events)))
            : $currentEvents;

        // The catalog is the vocabulary, and `requireEvents()` enforces it on create. An update that
        // names events must clear the same bar, or a PATCH is a second door onto a subscription that
        // can never fire.
        if ($input->events !== null) {
            $this->requireCatalogNames($events);
        }

        if ($type !== $currentType || $id !== $currentId) {
            $this->authorize($events, $this->resolveSubject($type, $id));

            return;
        }

        // Subject unchanged. A SUBJECTLESS hook's reach is its event set, so widening that set is the
        // same re-point wearing another spelling — vet only what was ADDED.
        if ($currentType === null && $input->events !== null) {
            $added = array_values(array_diff($events, $currentEvents));

            if ($added !== []) {
                $this->authorize($added, null);
            }
        }
    }

    /**
     * Resolve the optional subject morph, refusing a half-supplied pair.
     *
     * `subject_type` without `subject_id` (or the reverse) is not "no subject" — it is a caller who
     * believes they scoped their subscription and did not. Honouring it as a firehose over the whole
     * resource is the failure the subject-deletion rule exists to prevent, arriving through the front
     * door instead.
     *
     * @throws ValidationException
     */
    public function resolveSubject(?string $type, ?string $id): ?Model
    {
        if ($type === null && $id === null) {
            return null;
        }

        if ($type === null || $id === null) {
            throw ValidationException::withMessages([
                'subject_type' => 'Supply both subject_type and subject_id, or neither.',
            ]);
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw ValidationException::withMessages([
                'subject_type' => "`{$type}` is not a model this host knows.",
            ]);
        }

        $subject = $class::query()->find($id);

        if ($subject === null) {
            throw ValidationException::withMessages([
                'subject_id' => 'No such record to subscribe against.',
            ]);
        }

        return $subject;
    }

    /**
     * Every name must be in the live catalog. A subscription naming something outside it is a
     * subscription that can never fire — a caller mistake, so a 422 against `events`, never a 500.
     *
     * @param  list<string>  $events
     *
     * @throws ValidationException
     */
    public function requireCatalogNames(array $events): void
    {
        $unknown = array_values(array_filter($events, fn (string $name) => ! $this->catalog->has($name)));

        if ($unknown === []) {
            return;
        }

        throw ValidationException::withMessages([
            'events' => 'Not in the event catalog: '.implode(', ', $unknown)
                .'. Legal names: '.(implode(', ', $this->catalog->names()) ?: '(this host publishes none)').'.',
        ]);
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
            $resource = $this->resources->get($resourceKey);
        } catch (Throwable) {
            return null;
        }

        // ⚠️ Ask for the MODEL, not the raw `backing:` slot. `$resource->backing` is a
        // `ResourceBacking|class-string` — since particle-contribution-seam 11 it may name a BACKING
        // class rather than a model, and this method's return is fed straight to
        // `Gate::authorize('viewAny', …)` at `:86-92`. Handing the Gate a backing class-string gates
        // against a class with no policy.
        //
        // Live instance, 2026-08-28: `laravel-beam-accounts` moved `users` from `backing: User::class`
        // to `backing: ConfiguredUserBacking::class` so the model follows host config. `class_exists()`
        // is true for a backing, so the old guard let it straight through and `users.*` hook
        // subscriptions started gating on a non-model.
        if (($model = $resource->modelClass()) !== null) {
            return $model;
        }

        // A backing that declares NO single model (`members` → `MembershipSource`, `review-queue`)
        // reaches here. Deliberately preserving the pre-existing behaviour — return the backing
        // class-string — rather than returning null.
        //
        // ⚠️ This is NOT an endorsement of it. `Gate::authorize('viewAny', MembershipSource::class)`
        // finds no policy and DENIES, so a hook spanning those events cannot be created; that is very
        // likely its own bug. But the caller's `null` branch is `continue` (`:88-90`), i.e. NO check at
        // all — so "fixing" this to null would silently turn a deny into an allow on an authorization
        // path. That is a security decision with its own evidence to gather, not a tidy-up to fold into
        // a backing rename. Left exactly as it was; flagged for its own ticket.
        return class_exists($resource->backing) ? $resource->backing : null;
    }
}
