<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Support\Facades\Gate;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * `resource.read.open-list`: the ratchet on the list read's one remaining open pass (launch security row 51a71469).
 *
 * The list read asks the bound policy's viewAny. A policy that defines NO viewAny keeps the pass, exactly as
 * `ResourceVisibility::listable()` reads it, so such a resource, model-backed and row-unscoped, lists to any signed-in
 * actor. Each one must be accepted by name in `beam.core.reads.open_list` (key => why an open list is safe there: rows
 * scoped by a handler, a realm gate refusing at the socket, intentionally public content); an unaccepted one WARNS, so a
 * new one cannot join silently.
 */
class OpenListResourceAudit implements DoctorAudit
{
    public const CHECK = 'resource.read.open-list';

    /**
     * What beam itself accepts: nothing. `saved-filters` was accepted here until SavedFilterPolicy declared its viewAny
     * deliberately; a beam resource whose list is safe declares so on its policy, not in this list.
     */
    public const BEAM_ACCEPTED = [];

    public function __construct(
        private ParticleResourceRegistry $resources,
        private ResourceReadGuard $guard,
    ) {}

    public static function forApp(): self
    {
        return new self(app(ParticleResourceRegistry::class), ResourceReadGuard::forApp());
    }

    /** @return array<string, string> accepted resource key => why its open list is safe */
    public static function accepted(): array
    {
        return [...self::BEAM_ACCEPTED, ...(array) config('beam.core.reads.open_list', [])];
    }

    /** @return list<Finding> */
    public function run(): array
    {
        $accepted = self::accepted();
        $findings = [];

        foreach ($this->resources->all() as $resource) {
            if ($this->guard->policyBound($resource) !== true || $this->guard->policyHasViewAny($resource)
                || $this->guard->scoped($resource) === true) {
                continue;
            }

            $model = (string) $resource->modelClass();
            $policy = Gate::getPolicyFor($model);
            $policyName = is_object($policy) ? $policy::class : (string) $policy;

            $findings[] = isset($accepted[$resource->key])
                ? Finding::pass(self::CHECK, sprintf('[%s] lists to any signed-in actor (policy %s defines no viewAny), accepted: %s', $resource->key, $policyName, $accepted[$resource->key]))
                : Finding::warn(self::CHECK, sprintf(
                    '[%s] lists in full to any signed-in actor: its model %s is bound to %s, which defines no viewAny, and its rows '
                    .'are not scoped. Add a viewAny to the policy, scope the rows, or accept it by name in '
                    .'beam.core.reads.open_list with the reason an open list is safe here.',
                    $resource->key, $model, $policyName,
                ));
        }

        return $findings !== [] ? $findings : [Finding::pass(self::CHECK, 'There is no model-backed resource whose list is open by a policy without viewAny.')];
    }
}
