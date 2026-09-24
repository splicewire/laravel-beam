<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\ParticleListQuery;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Realm\RealmEntitlementResourceGate;
use Splicewire\Beam\Surface\RuntimeCorroborator;
use Throwable;

/** Reads policy, tenancy, declaration-derived row boundaries and hard realm entitlements without executing the query. */
class ResourceReadGuard
{
    public static function forApp(): self
    {
        return new self;
    }

    /** Inspect the declared read boundary before caller filters or record selection can narrow it. */
    public function inspectRead(ParticleResource $resource, Request $request): Response
    {
        return $this->inspect($resource, $request, Gate::getFacadeRoot());
    }

    /**
     * The same decision as {@see inspectRead()}, asked for a NAMED actor rather than the ambient one — so
     * a surface that offers a resource ({@see ResourceVisibility::listable()}: the nav, the realm
     * dashboard's cards, the registry) answers from this boundary instead of a parallel rule. A null
     * actor is a guest: every Gate arm is asked of nobody.
     */
    public function inspectReadFor(ParticleResource $resource, Request $request, ?Authenticatable $actor): Response
    {
        return $this->inspect($resource, $request, Gate::forUser($actor));
    }

    private function inspect(ParticleResource $resource, Request $request, GateContract $gate): Response
    {
        if ($this->policyBound($resource) !== false) {
            return Response::allow();
        }

        $route = $request->route();
        if ($route instanceof Route) {
            $middleware = [];
            foreach ([...$route->middleware(), ...app('router')->gatherRouteMiddleware($route)] as $entry) {
                $middleware[] = is_string($entry) ? $entry : (is_object($entry) ? $entry::class : (string) json_encode($entry));
            }
            if (self::suppliesScope($middleware)) {
                return Response::allow();
            }
        }

        if ($this->scoped($resource, $request) !== false) {
            return Response::allow();
        }

        // A hard realm entitlement the caller holds: the realm gate has already refused everyone else at
        // the socket, so this population is exactly what the caller was authorized for.
        if (app(RealmEntitlementResourceGate::class)->entitledThroughRealm($resource->key, $gate)) {
            return Response::allow();
        }

        return $gate->inspect('viewAny', $resource->modelClass());
    }

    /** Null means this environment could not build the declared authorization bases. */
    public function scoped(ParticleResource $resource, ?Request $request = null): ?bool
    {
        if (! $resource->backing() instanceof QueriesRecords) {
            return null;
        }
        try {
            $bases = app(ParticleListQuery::class)->authorizationBases($resource, $request ?? Request::create('/'));
            foreach ($bases as $builder) {
                $base = $builder instanceof EloquentBuilder ? $builder->toBase() : $builder->getQuery();
                if (($base->wheres ?? []) !== [] || ($base->joins ?? []) !== []) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Does the declared boundary admit NO row for this caller? True when some authorization base is
     * narrowed, at its top level, by the estate's fail-closed predicate `1 = 0` — what
     * {@see RowAuthorization::apply()} and the cascade's `scopeForUser()` return for an actor holding no
     * view token. The bases are intersected, so one empty base empties the whole population.
     *
     * {@see scoped()} reads structure and counts that predicate as a scope. A scope that admits nothing
     * is a refusal, not an ownership boundary, and it cannot vouch for anything read beside the rows —
     * measured 2026-09-24 at `~/Herd/splicewire-app`: a user holding no permission read `fragments`'
     * filter schema and its silo option LABELS because `where 1 = 0` answered `scoped() === true`.
     * False when the bases cannot be built (the {@see scoped()} null case decides that on its own).
     */
    public function admitsNoRows(ParticleResource $resource, ?Request $request = null): bool
    {
        if (! $resource->backing() instanceof QueriesRecords) {
            return false;
        }
        try {
            $bases = app(ParticleListQuery::class)->authorizationBases($resource, $request ?? Request::create('/'));
        } catch (Throwable) {
            return false;
        }

        foreach ($bases as $builder) {
            $base = $builder instanceof EloquentBuilder ? $builder->toBase() : $builder->getQuery();
            foreach ($base->wheres ?? [] as $where) {
                if (($where['type'] ?? null) === 'raw' && ($where['boolean'] ?? 'and') === 'and'
                    && in_array(preg_replace('/\s+/', '', (string) ($where['sql'] ?? '')), ['1=0', '0=1'], true)) {
                    return true;
                }
            }
        }

        return false;
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
