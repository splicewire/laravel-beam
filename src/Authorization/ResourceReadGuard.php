<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use ReflectionMethod;
use Rushing\DataFilters\DataFilterManager;
use Splicewire\Beam\Doctor\UngatedResourceReadAudit;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Surface\RuntimeCorroborator;
use Throwable;

/**
 * The three facts that decide whether a resource's LIST read is gated — read off the wire, not the
 * source (beam-docs-satellite 65).
 *
 * `ParticleController::index()` does not `authorize()`. Its contract says the read gate is the list
 * query's own row-scoping, and for a `filterable` resource that is the data-filters base query; for a
 * non-filterable one it is the declared `scope` closure. 65 measured three package queries whose
 * docblocks NAMED a gate — "the tenant schema the connection resolves to, plus the middleware on each
 * host's mount" — that no code enforced, and the flagship mounted one of them centrally with no tenancy.
 * Prose nominates; only a predicate, a policy, or a tenancy initializer authorizes. This class reads those
 * three, and nothing else. Two readers: {@see ParticleController::denyUngatedRead()}
 * fails the read closed at REQUEST time, and {@see UngatedResourceReadAudit} lists the
 * mounts that would be denied before a caller finds out. Neither runs at the mount: whether a host's mount
 * is scoped is a host fact, and a host must boot (AGENTS.md).
 *
 * ## Why the query is BUILT rather than the class inspected
 *
 * Two of the three measured queries override `baseQuery()` for ORDERING and add no predicate
 * (`HookResourceQuery`, `BeamUxEntryResourceQuery`). "Does it override?" would call both scoped; "does
 * the built base carry a where or a join?" does not, and that is the reading the row plane actually
 * runs on. The build is done through `toBase()`, so a model's global scopes count as predicates too — a
 * `HasVisibility` scope is a scope. No query is executed.
 *
 * ## Three answers, and the third is not "no"
 *
 * Every reader returns `null` for "could not look": a filterable key with no data-filters registration
 * (its index would throw `BadMethodCallException` anyway), a base query that cannot be built outside a
 * request or a tenant connection, a backing with no Eloquent model. The read gate treats `null` as
 * "serve, and let the audit say so"; the audit carries the count. An instrument whose "didn't look" is
 * spelled like "nothing there" is the estate's signature defect.
 *
 * `policyBound()` is any bound policy, not only a cascade one — {@see RowAuthorization::resolvable()} is
 * the stricter probe for whether the cascade can NARROW a list; this one answers 65's coarser question,
 * "did an author bind anything at all". ⚠️ A bound policy is not otherwise consumed by `index()` today — it gates
 * `show`/`destroy`/the writes and the filter sub-surface's `viewAny`. Whether the index should apply
 * {@see RowAuthorization} when a cascade policy is bound is a separate ruling (65 records it), not a
 * fact this class can answer.
 */
class ResourceReadGuard
{
    public function __construct(private ?DataFilterManager $filters) {}

    /**
     * Resolve off the container, degrading to "cannot read filterable resources" where data-filters is
     * not bound — beam hard-requires it, but `declareFilterResources()` already guards the same seam.
     */
    public static function forApp(): self
    {
        return new self(app()->bound(DataFilterManager::class) ? app(DataFilterManager::class) : null);
    }

    /**
     * Does this resource's list base narrow its rows?
     *
     * `true` — a predicate (where/join, including a global scope) or a declared `scope` closure;
     * `false` — the base is bare, or orders only;
     * `null` — could not look (see the class docblock).
     */
    public function scoped(ParticleResource $resource, ?Request $request = null): ?bool
    {
        if (! $resource->filterable) {
            return $resource->scope !== null;
        }

        if ($this->filters === null || ! $this->filters->registry()->has($resource->key)) {
            return null;
        }

        try {
            $query = $this->filters->query($resource->key);
            $builder = (new ReflectionMethod($query, 'baseQuery'))->invoke($query, $request ?? Request::create('/'));

            $base = $builder instanceof EloquentBuilder ? $builder->toBase() : $builder->getQuery();

            return ($base->wheres ?? []) !== [] || ($base->joins ?? []) !== [];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether a data-filters resource is registered under this key at all — the cheap half of a `null`
     * from {@see scoped()} on a filterable resource, so a reader can say "no registration" (the index
     * would throw `BadMethodCallException` before it ever reached the gate) rather than "could not build".
     */
    public function filterRegistered(ParticleResource $resource): bool
    {
        return $this->filters !== null && $this->filters->registry()->has($resource->key);
    }

    /**
     * Is ANY policy bound for the resource's model? `null` when the backing names no Eloquent model —
     * a backing that streams its own records is outside the row plane this class reads.
     */
    public function policyBound(ParticleResource $resource): ?bool
    {
        $model = $resource->modelClass();

        if ($model === null) {
            return null;
        }

        return Gate::getPolicyFor($model) !== null;
    }

    /**
     * Does this middleware stack supply the scope — i.e. initialize tenancy, so the connection resolves
     * to a tenant schema rather than central? Positively determinable only: an initializer proves `true`,
     * its absence proves nothing about the connection (RuntimeCorroborator's own rule), which is why the
     * read gate pairs it with the two resource-side facts rather than reading it alone.
     *
     * @param  list<string>  $middleware  route or group middleware, resolved or not — the matcher takes
     *                                    aliases (`tenant`), class-strings, and namespace prefixes alike
     */
    public static function suppliesScope(array $middleware): bool
    {
        return RuntimeCorroborator::middlewareMatches($middleware, RuntimeCorroborator::DEFAULT_TENANCY_MIDDLEWARE);
    }
}
