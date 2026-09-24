<?php

namespace Splicewire\Beam\Particle;

use Illuminate\Database\Eloquent\Model;
use Schemastud\Frame\Contracts\WriteSubjectResolver;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\Backing\QueriesRecords;

/**
 * Beam's answer to frame's {@see WriteSubjectResolver}: the record a write policy is asked about is
 * looked up through the SAME scoped query {@see ParticleFrameResourceHandler::query()} resolves it
 * through — the backing's query with the declaration's row-level `scope` applied
 * ({@see ScopedIndexQuery::scoped()}).
 *
 * Without it frame resolved the id unscoped, so a row outside the caller's reach was found, its policy
 * refused it, and the socket answered 403 where the handler's scoped `findOrFail` answers 404 — the
 * gate reporting that a foreign row exists. `TokenPolicy`'s own docblock assumed the 404 ("an admin's
 * request 404s before authorization matters"); measured 2026-09-24 at `~/Herd/splicewire-app` it was
 * a 403 for another user's token and for an accepted invitation.
 *
 * A key with no registered declaration, or one whose backing cannot compose a query, keeps frame's
 * unscoped model lookup — there is no declared scope to apply, so nothing narrower exists to ask.
 */
class ParticleWriteSubjectResolver implements WriteSubjectResolver
{
    public function __construct(
        protected ParticleResourceRegistry $particles,
        protected ScopedIndexQuery $scopes,
    ) {}

    public function resolve(ResourceDefinition $definition, string $id): ?Model
    {
        $resource = $this->particles->find($definition->key);

        if ($resource === null || ! $resource->backing() instanceof QueriesRecords) {
            $model = $definition->model;

            return $model === null ? null : $model::query()->find($id);
        }

        return $this->scopes->scoped($resource->backing()->query([]), $resource)->find($id);
    }
}
