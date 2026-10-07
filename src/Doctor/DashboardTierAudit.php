<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Container\Container;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Schemastud\Frame\Contracts\ResourceSummaryProvider;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Dashboard\DashboardParticipation;
use Splicewire\Beam\Dashboard\RailLeaves;
use Splicewire\Beam\Dashboard\RealmDashboard;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\ScopedIndexQuery;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Summary\BeamResourceSummaryProvider;
use Throwable;

/**
 * Audits declared dashboard cards using the same participation policy as DashboardBacking.
 * A summary/overview context or seated custom provider declares a card. Rail presence alone
 * does not: default derived cards are disabled by UX-14. Developer seats are excluded.
 *
 * The actor-free declared rail keeps gated resources in the provider-health population.
 * DECLARED cards pass; ABSENT cards warn when their provider cannot answer. Authorization
 * refusals are UNKNOWN (inconclusive): the actor-free audit could not check provider health. A host with no
 * eligible declarations is inconclusive, never a vacuous pass. The reported 0 DERIVED is the
 * participation policy's invariant, not a provider-health outcome. Participation tests pin that
 * a seated, countable resource without a declaration never enters the card population.
 *
 * Custom providers are called under a guard; exceptions become advisory findings. Actor
 * gates, mounted routes and the provider's actor-dependent answer still need host evidence.
 */
class DashboardTierAudit implements DoctorAudit
{
    public const CHECK = 'particle.dashboard-tier';

    public function __construct(
        private ParticleResourceRegistry $resources,
        private RealmRegistry $realms,
        private NavSectionRegistry $sections,
        private ScopedIndexQuery $query,
        private Container $container,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $declared = 0;
        $absent = [];
        $unknown = [];

        foreach (array_keys($this->realms->all()) as $realm) {
            $rail = RailLeaves::declaredFor($realm, $this->sections, $this->resources);

            foreach ($this->resources->keysForRealm($realm) as $key) {
                $resource = $this->resources->find($key);

                if ($resource === null || ! $resource->isFramed() || RealmDashboard::isKey($key, $realm)) {
                    continue;
                }

                try {
                    $definition = $this->resources->definition($key, $realm);
                } catch (Throwable) {
                    continue; // a declaration that cannot be projected is another audit's finding
                }

                if (DashboardParticipation::contextFor($definition, $rail) === null) {
                    continue; // not an eligible card; a product rail destination may still render as a tile
                }

                $name = sprintf('[%s/%s]', $realm, $key);
                $custom = $this->customProvider($resource);

                if (! $custom && ! $this->query->queryable($definition)) {
                    $absent[$name] = $name.' (default provider over a backing that cannot count)';

                    continue;
                }

                if ($custom) {
                    try {
                        $declines = $this->declines($definition);
                    } catch (AuthorizationException) {
                        $unknown[$name] = $name;

                        continue;
                    }

                    if ($declines !== null) {
                        $absent[$name] = $name.' ('.$declines.')';

                        continue;
                    }
                }

                $declared++;
            }
        }

        $total = $declared + count($absent) + count($unknown);

        if ($total === 0) {
            return [Finding::inconclusive(self::CHECK, 'No eligible declared dashboard cards here; there is no card-provider health population to audit. Product rail destinations may still render as tiles. 0 DERIVED (disabled by participation policy).')];
        }

        $findings = [];

        if ($absent !== []) {
            ksort($absent);

            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%d of %d dashboard card%s %s ABSENT — the resource is on the dashboard and its provider cannot answer, so the card is dropped: %s. '
                .'0 DERIVED (disabled by participation policy). Name a `summaryProvider:` that can summarize the backing, or opt out with `#[Summary(false)]`.',
                count($absent),
                $total,
                $total === 1 ? '' : 's',
                count($absent) === 1 ? 'is' : 'are',
                implode('; ', $absent),
            ));
        }

        if ($unknown !== []) {
            ksort($unknown);

            $findings[] = Finding::inconclusive(self::CHECK, sprintf(
                '%d of %d dashboard card%s %s UNKNOWN — authorization prevented the actor-free audit from checking provider health: %s. '
                .'Cannot check here; verify with a permitted signed-in actor. 0 DERIVED (disabled by participation policy).',
                count($unknown),
                $total,
                $total === 1 ? '' : 's',
                count($unknown) === 1 ? 'is' : 'are',
                implode('; ', $unknown),
            ));
        }

        if ($findings !== []) {
            return $findings;
        }

        return [Finding::pass(self::CHECK, sprintf(
            '%d dashboard card%s, every one on the declared tier (a `summary`/`overview` binding or a custom provider). 0 DERIVED (disabled by participation policy).',
            $total,
            $total === 1 ? '' : 's',
        ))];
    }

    private function customProvider(ParticleResource $resource): bool
    {
        return $resource->summaryProvider !== null && $resource->summaryProvider !== BeamResourceSummaryProvider::class;
    }

    /**
     * Why a custom provider yields no card — null when it answers.
     *
     * @throws AuthorizationException when authorization prevents measuring the provider
     */
    private function declines(ResourceDefinition $definition): ?string
    {
        try {
            $provider = $this->container->make($definition->summaryProvider);

            if (! $provider instanceof ResourceSummaryProvider) {
                return 'summary provider '.$definition->summaryProvider.' does not implement ResourceSummaryProvider';
            }

            return $provider->summary($definition) === null ? 'custom provider declines' : null;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return 'custom provider threw '.$e::class;
        }
    }
}
