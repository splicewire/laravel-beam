<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Authorization\ModelLessReadPosture;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * A resource with no Eloquent model whose read gate is undeclared — or declared in a spelling that cannot
 * work.
 *
 * An UNDECLARED one is listed in the nav to every authenticated actor its realm admits, served by the
 * frame sockets on realm reach alone, and its filter vocabulary is gated by the route middleware alone.
 * That is app ADR-0119 §2's decided posture (*"skip the check; the API layer still enforces"*), and
 * {@see ResourceVisibility} serves it unchanged — so this is the instrument that says, per host, how much
 * of the registry rests on that sentence being true of each backing. The ruling's premise is a claim
 * about every model-less backing's own scoping, and nothing else checks it.
 *
 * A declared `policy:` that is a CLASS-string (the model-backed `policy: UserPolicy::class` idiom) names no
 * Gate ability: every non-superuser is refused. It fails closed, so it is not a leak, but it is a
 * declaration that cannot mean what its author meant, and it would otherwise count as gated.
 *
 * ## What a pass does NOT say
 *
 * A declared ability gates the surfaces {@see ResourceVisibility} lists and no others — not a hand-written
 * route that stamps `->inResource(key)`, and not the manifest's metadata blocks. This audit counts
 * declarations; it cannot see every controller that serves a resource-shaped response.
 *
 * ## Why an advisory, and why a warn rather than a pass
 *
 * Which resources a host registers is a fact about the host (AGENTS.md: a check whose answer depends on
 * the host never throws), and the posture is decided, so this never fails the exit code. It still warns:
 * an undeclared posture is an omission spelled like a decision, and the count reaching zero is what a
 * future flip to deny-by-default would be gated on — the schedule `UngatedOperationAudit` keeps for
 * `ParticleOperation`'s `ability: null`.
 *
 * Walks the BOOTED registry, never the source: `members` is registered imperatively from
 * `WiresFrameResources` and `review-queue` from a provider's array, and neither carries an attribute a
 * grep could find.
 */
class ModelLessReadGateAudit implements DoctorAudit
{
    public const CHECK = 'particle.model-less-read-gate';

    public function __construct(
        private ParticleResourceRegistry $resources,
        private ResourceVisibility $visibility,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $resources = $this->resources->all();

        if ($resources === []) {
            return [Finding::inconclusive(self::CHECK, 'No particle resource is registered on this host — there is no model-less read to classify.')];
        }

        $declared = 0;
        $undeclared = [];
        $policyClasses = [];

        foreach ($resources as $resource) {
            $posture = $this->visibility->posture($resource);

            if ($posture === null) {
                continue;
            }

            if ($posture === ModelLessReadPosture::DeclaredAbility) {
                $declared++;

                if ($this->visibility->declaresPolicyClass($resource)) {
                    $policyClasses[$resource->key] = sprintf('[%s] (policy %s)', $resource->key, $resource->policy);
                }

                continue;
            }

            $undeclared[$resource->key] = sprintf(
                '[%s] (backing %s, realms [%s], %s)',
                $resource->key,
                is_string($resource->backing) ? $resource->backing : $resource->backing::class,
                implode(', ', $this->resources->realmsFor($resource->key)),
                $resource->section === null ? 'no nav section' : "section [{$resource->section}]",
            );
        }

        $modelLess = $declared + count($undeclared);

        if ($modelLess === 0) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d particle resource%s read; none is model-less, so every read has a model for a policy to be about.',
                count($resources),
                count($resources) === 1 ? '' : 's',
            ))];
        }

        $findings = [];

        if ($undeclared !== []) {
            ksort($undeclared);

            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%d of %d model-less resource%s declare%s no read gate: %s. Each is listed to every authenticated '
                .'actor its realm admits and served on realm reach alone — app ADR-0119 §2\'s posture, which holds '
                .'only if the backing narrows every record to the actor itself. Declare `policy:` with the '
                .'subject-free ability a reader must hold.',
                count($undeclared),
                $modelLess,
                $modelLess === 1 ? '' : 's',
                count($undeclared) === 1 ? 's' : '',
                implode('; ', $undeclared),
            ));
        }

        if ($policyClasses !== []) {
            ksort($policyClasses);

            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%d model-less resource%s declare%s a policy CLASS where a read ability is read: %s. A class-string '
                .'names no Gate ability, so every reader but a `Gate::before` superuser is refused. Declare the '
                .'subject-free ability (a permission name, `entitlement:{key}`, or a user-only `Gate::define`).',
                count($policyClasses),
                count($policyClasses) === 1 ? '' : 's',
                count($policyClasses) === 1 ? 's' : '',
                implode('; ', $policyClasses),
            ));
        }

        if ($findings !== []) {
            return $findings;
        }

        return [Finding::pass(self::CHECK, sprintf(
            '%d model-less resource%s read, and every one declares a read ability.',
            $modelLess,
            $modelLess === 1 ? '' : 's',
        ))];
    }
}
