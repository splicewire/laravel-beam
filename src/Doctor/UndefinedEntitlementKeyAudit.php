<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Entitlements\EntitlementKeyUniverse;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;

/**
 * An `entitlement:{key}` this host references but no source declares (tutorial-dx 04). Exec ADR-0001 stands: an undefined
 * key denies everyone. That is the right default and a silent one: calcucrypt's ch-04 locked paying readers out with no
 * error anywhere. This audit makes it visible; it changes no decision.
 *
 * STATIC and ADVISORY. It reads what the host declares: route middleware (`can:entitlement:*`) and particle operations'
 * `ability:`/`abilityModel:`. It does not see a Gate check in PHP, a Blade `@can`, a policy, or an ability string built
 * at runtime, so its pass says "none of the references it can read is undefined", not "none is"; every finding says so.
 * A warning, never a failure: a referenced key a package contributes only in some installs is not this audit's to rule.
 */
class UndefinedEntitlementKeyAudit implements DoctorAudit
{
    public const CHECK = 'entitlements.undefined-key';

    public const COVERAGE = 'Coverage is incomplete by design: this audit reads route middleware (`can:entitlement:*`) and '
        .'particle operations\' `ability:`/`abilityModel:`. Gate checks in PHP, Blade `@can`, policies and ability strings '
        .'built at runtime are not seen.';

    public function __construct(
        protected ParticleOperationRegistry $operations,
        protected EntitlementKeyUniverse $universe,
        protected Router $router,
    ) {}

    public function run(): array
    {
        $references = $this->references();

        if ($references === []) {
            return [Finding::inconclusive(self::CHECK, 'No `entitlement:*` reference was found to check. '.self::COVERAGE)];
        }

        $known = array_flip($this->universe->keys());
        $undefined = array_diff_key($references, $known);

        if ($undefined === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'All %d referenced entitlement keys are known to the key universe, so each has its `entitlement:{key}` ability. %s',
                count($references),
                self::COVERAGE,
            ))];
        }

        ksort($undefined);

        return [Finding::warn(self::CHECK, sprintf(
            "%d of %d referenced entitlement keys are known to no source, so their `entitlement:{key}` ability is never "
            ."defined and Exec ADR-0001 denies them to everyone, silently:\n%s\n\nDeclare each where its plan or grant "
            .'lives: `app.entitlements`, `beam.core.entitlements.keys`, a beam-accounts bundle or grant source. Or correct '
            .'the reference. %s',
            count($undefined),
            count($references),
            implode("\n", array_map(
                fn (string $key, array $where): string => sprintf('  %-36s ← %s', $key, implode('; ', array_unique($where))),
                array_keys($undefined),
                $undefined,
            )),
            self::COVERAGE,
        ))];
    }

    /** @return array<string, list<string>> each referenced key → where it is referenced */
    protected function references(): array
    {
        $references = [];

        foreach ($this->router->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && preg_match('/^can:entitlement:([^,]+)/', $middleware, $m) === 1) {
                    $references[$m[1]][] = sprintf('route %s %s', implode('|', $route->methods()), $route->uri());
                }
            }
        }

        foreach ($this->operations->unfiltered()->matches('beam.particle.operations') as $operation) {
            /** @var ParticleOperation $operation */
            foreach (['ability' => $operation->ability, 'abilityModel' => $operation->abilityModel] as $slot => $ability) {
                if (is_string($ability) && str_starts_with($ability, 'entitlement:')) {
                    $references[substr($ability, strlen('entitlement:'))][] = sprintf('operation %s %s:', $operation->key(), $slot);
                }
            }
        }

        return $references;
    }
}
