<?php

namespace Splicewire\Beam\Authorization;

use ArgumentCountError;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Doctor\ModelLessReadGateAudit;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Backing\BacksModel;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmEntitlementResourceGate;
use TypeError;

/**
 * The ONE answer to *"may this actor see this resource?"* for a resource with no Eloquent model — asked by
 * the nav collectors for a listing and by the socket and filter sub-surface for a read, so the rail and
 * the wire cannot answer it two ways.
 *
 * ## The hole this closes, and the ruling it does not reverse
 *
 * A resource whose backing names no Eloquent model skipped the question at every reader: the nav
 * collector returned `true` on `$def->model === null` before asking anything, the socket gates never
 * looked past realms, and the filter sub-surface gated "on the route middleware alone". There was no
 * declaration that could make one of them refuse (DESIGN-02, otb-ui-frontier-sidebar — the registry area
 * that would list every resource is exactly where that default must not be taken silently).
 *
 * The skip is DECIDED, not accidental. App ADR-0119 §2 (accepted 2026-07-23): *"Service-backed union
 * resources (no model) and unauthenticated contexts skip the check (the API layer still enforces)."* The
 * unauthenticated half of that sentence was reversed on measurement on 2026-09-05; the model-less half
 * stands. So this class does not flip it. It gives a model-less resource a way to declare a read gate
 * ({@see ModelLessReadPosture}) and makes the undeclared posture countable ({@see ModelLessReadGateAudit})
 * — the schedule `ParticleOperation`'s `ability: null` is on.
 *
 * ## Why the declared ability rides `policy:`, and what that slot must hold here
 *
 * No new slot. `policy:` is the declaration's "ability/policy key the injected can() resolves against"
 * (and `RuntimeCorroborator` already reports it as a route's ability). For a model-less resource it had no
 * consumer on any read path, and frame's `ResourceAuthorizer::allows()` refuses a model-less write outright
 * wherever that authorizer runs. On a MODEL-BACKED resource this class never consults it: {@see readable()}
 * returns true there, whose read gate is its row scope and its model's `viewAny` (api-surface-coherence
 * 135).
 *
 * The ability is asked SUBJECT-FREE, so it must be a Gate ability taking only the user (a permission
 * name, `entitlement:{key}`, or a `Gate::define` whose closure has no subject parameter). Two wrong
 * spellings fail CLOSED rather than open, and the audit names both: a policy CLASS-string (the
 * `policy: UserPolicy::class` idiom from a model-backed declaration) is no ability and is refused to
 * every non-superuser, and an ability whose callback demands a subject would throw — that throw is caught
 * and read as a refusal, because a nav build or a socket request must not crash on one declaration.
 *
 * ## Which surfaces read it — and which do not
 *
 * Read: both nav collectors (`Splicewire\Beam\Ux\Frame\FrameResourcesInvocable` and the flagship's live
 * `App\Navigation\FrameResourcesInvocable`), beam's {@see RealmEntitlementResourceGate} on frame's package
 * socket, the flagship's own `App\Http\Controllers\Api\Frame\FrameResourceController` socket, and beam's
 * `Filters\ResourceFilters` runtime.
 *
 * NOT read, and so NOT gated by a declared ability: hand-written routes that merely stamp
 * `->inResource(key)` (tower's `ReviewQueueController`, `ReviewInboxController`, the discovery and hook
 * event-catalog sub-routes), and the manifest's `resources` / `contexts` / `routeContext` metadata
 * blocks. Those are resource-SHAPED surfaces with their own controllers; each owns its authorization, and
 * a declaration here does not reach them.
 *
 * ## Two questions, one answer
 *
 *  - {@see readable()} — the wire. Only the model-less declared-ability arm can refuse. The actor may be
 *    null (a guest the route middleware let through): the Gate denies a guest any ability whose callback
 *    types its user. An UNDECLARED resource is not refused to a guest here, because authenticating a
 *    request is the route middleware's job.
 *  - {@see listable()} — the nav. {@see readable()}, plus the model-backed `viewAny` reading moved here
 *    verbatim from the package collector, plus one bound: a null actor is never SHOWN a model-less
 *    resource — the 2026-09-05 rule (*anonymous is bounded above by authenticated*) applied to the arm
 *    that used to return before it could run.
 */
class ResourceVisibility
{
    public function __construct(
        private ParticleResourceRegistry $particles,
        private Gate $gate,
        private ?BackingResolver $backings = null,
    ) {
        $this->backings ??= new BackingResolver;
    }

    /**
     * May `$actor` READ this resource? Refuses only a model-less resource whose declared ability the actor
     * does not hold — see the class docblock for why every other arm answers true here.
     */
    public function readable(ResourceDefinition|ParticleResource $resource, ?Authenticatable $actor): bool
    {
        if ($this->posture($resource) !== ModelLessReadPosture::DeclaredAbility) {
            return true;
        }

        try {
            return $this->gate->forUser($actor)->allows($this->policyOf($resource));
        } catch (ArgumentCountError|TypeError) {
            // An ability whose callback demands a subject — a write-shaped ability declared where a
            // subject-free read one belongs. Refused rather than thrown: see the class docblock.
            return false;
        }
    }

    /**
     * Whether a declared ability is spelled as a policy CLASS rather than an ability — the idiom a
     * model-backed declaration uses, which on a model-less one names no Gate ability and refuses everyone
     * but a `Gate::before` superuser. False for an undeclared or model-backed resource.
     */
    public function declaresPolicyClass(ResourceDefinition|ParticleResource $resource): bool
    {
        return $this->posture($resource) === ModelLessReadPosture::DeclaredAbility
            && class_exists($this->policyOf($resource));
    }

    /**
     * May `$actor` be SHOWN this resource in a listing (a nav seat, a registry row)?
     *
     * A model-backed resource: its model's `viewAny` when a policy declaring one is bound, and visible
     * otherwise — a missing policy on a READ falls through to the row scope (api-surface-coherence 135,
     * ADR-0156 §83), and a null actor cannot satisfy a bound `viewAny` (2026-09-05). A model-less resource:
     * never to a null actor, and otherwise {@see readable()}.
     */
    public function listable(ResourceDefinition $definition, ?Authenticatable $actor): bool
    {
        if ($definition->model === null) {
            return $actor !== null && $this->readable($definition, $actor);
        }

        $policy = $this->gate->getPolicyFor($definition->model);

        if ($policy === null || ! method_exists($policy, 'viewAny')) {
            return true;
        }

        return $actor !== null && $this->gate->forUser($actor)->allows('viewAny', $definition->model);
    }

    /**
     * Which read posture a model-less resource declares, or null for a model-backed one (which has a
     * policy subject and is not this enum's population).
     */
    public function posture(ResourceDefinition|ParticleResource $resource): ?ModelLessReadPosture
    {
        if ($this->modelOf($resource) !== null) {
            return null;
        }

        return $this->policyOf($resource) !== ''
            ? ModelLessReadPosture::DeclaredAbility
            : ModelLessReadPosture::Undeclared;
    }

    /**
     * A declaration's model, asked STATICALLY first: a backing class that does not implement
     * {@see BacksModel} has no model, and saying so without resolving it matters on the wire, where
     * `ParticleResource::modelClass()` would construct the backing (and a backing may need a tenant
     * connection) just to be told null.
     */
    private function modelOf(ResourceDefinition|ParticleResource $resource): ?string
    {
        if (! $resource instanceof ParticleResource) {
            return $resource->model;
        }

        return $this->backings->hasCapability($resource->backing, BacksModel::class) ? $resource->modelClass() : null;
    }

    /**
     * The declared ability, `''` when none — read off the object handed in, never re-fetched. So a caller
     * holding a realm-projected frame definition is answered by that projection's `policy`, while one
     * holding the raw `ParticleResource` (the filter sub-surface, the audit) reads the declaration's own.
     * No realm override sets `policy` today; `config/frame.php` at the flagship records that overrides
     * reach the manifest, not the transport.
     */
    private function policyOf(ResourceDefinition|ParticleResource $resource): string
    {
        return (string) $resource->policy;
    }
}
