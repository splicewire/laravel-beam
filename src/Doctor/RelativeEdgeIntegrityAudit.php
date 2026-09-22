<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Database\Eloquent\Model;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Particle\ParticleRelative;
use Splicewire\Beam\Particle\ParticleRelativeRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/** Checks that relative edges name an Eloquent parent and a registered child resource.
 * Parent route binding does not by itself prove authorization; mount policies own that concern.
 */
class RelativeEdgeIntegrityAudit implements DoctorAudit
{
    public const CHECK = 'particle.relative-edge';

    public function __construct(
        private ParticleRelativeRegistry $relatives,
        private ParticleResourceRegistry $resources,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $edges = $this->relatives->all();

        if ($edges === []) {
            return [Finding::inconclusive(self::CHECK, 'No relative edge is declared on this host.')];
        }

        $rows = [];

        foreach ($edges as $edge) {
            foreach ($this->problems($edge) as $problem) {
                $rows[] = $problem;
            }
        }

        if ($rows === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d declared relative edge%s; every one scopes its child through the bound parent.',
                count($edges),
                count($edges) === 1 ? '' : 's',
            ))];
        }

        return [Finding::warn(self::CHECK, sprintf(
            '%d relative edge problem%s: %s',
            count($rows),
            count($rows) === 1 ? '' : 's',
            implode('; ', $rows),
        ))];
    }

    /** @return list<string> */
    private function problems(ParticleRelative $edge): array
    {
        $rows = [];
        $key = $edge->key();

        // The `model:` is what gets route-model-bound. A non-Eloquent class cannot be, and the edge is
        // honestly Eloquent-only (07 D5 refused a `RelatesRecords` port for it: zero non-Eloquent parents
        // with edges exist, and a port with no implementation is what ticket 06 dissolved 1,120 lines to
        // remove). Named here so the refusal is discoverable rather than silent.
        if (! is_subclass_of($edge->model, Model::class)) {
            $rows[] = sprintf(
                'edge [%s] declares `model: %s`, which is not an Eloquent model — the relative mount '
                    .'route-model-binds the parent, so a non-Eloquent parent cannot be expressed. The edge '
                    .'mechanism is Eloquent-only by construction',
                $key,
                $edge->model,
            );
        }

        $child = $this->resources->find($edge->child);

        // A host fact, and the honest answer is "report it": the child may be registered by a package this
        // host does not install, in which case the edge is inert rather than wrong.
        if ($child === null) {
            $rows[] = sprintf(
                'edge [%s] mounts child resource [%s], which is not registered on this host — the mount '
                    .'has nothing to expose',
                $key,
                $edge->child,
            );

            return $rows;
        }

        return $rows;
    }
}
