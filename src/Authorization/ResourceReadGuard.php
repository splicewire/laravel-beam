<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\ParticleListQuery;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Surface\RuntimeCorroborator;
use Throwable;

/** Reads policy, tenancy and declaration-derived row boundaries without executing the query. */
class ResourceReadGuard
{
    public static function forApp(): self
    {
        return new self;
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
