<?php

namespace Splicewire\Beam\Doctor;

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
 * Which of a realm's dashboard cards rest on the DERIVED tier, and which resources the dashboard would
 * draw but cannot — the triage instrument realm-dashboards ticket 04 asked for, so the state of a
 * host's dashboards is a measurement rather than a screenshot.
 *
 * ## The three tiers
 *
 * For every resource ON a realm's dashboard — a leaf of the realm's rail resolves to it, or it declares
 * `summary`/`overview`, and it is not opted out by `#[Summary(false)]` ({@see DashboardParticipation}):
 *
 *  - **declared** — its read Data class binds `summary` or `overview`, or it names its own summary
 *    provider. The card is what its author decided. PASS.
 *  - **derived** — no binding and the default {@see BeamResourceSummaryProvider}: the card is one `total`
 *    figure under the resource's label, drawn by the context-default widget. Honest, but nobody chose
 *    it. WARN, by name, so the list is the work-list.
 *  - **absent** — the resource would be on the dashboard and its provider cannot answer: the default
 *    provider over a backing that only streams, a custom provider that declines or throws. The dashboard
 *    silently drops the card. WARN, by name.
 *
 * ## One rule, two rails
 *
 * "Is this resource on the dashboard" is {@see DashboardParticipation::contextFor()} — the same call
 * beam-ux's `DashboardBacking` makes with the actor in hand. This audit has no actor, so it reads the
 * DECLARED rail ({@see RailLeaves::declaredFor()}) rather than the projected one: a gated tree read as a
 * guest hides every model-less resource, which is exactly the population the ABSENT tier names. A
 * resource the backing would hide from a given actor is still audited, because the tier is a fact about
 * the declaration, not about who is looking.
 *
 * ## Why an advisory
 *
 * Which resources a host places in a realm, and which realms it has, are host facts (AGENTS.md: a check
 * whose answer depends on the host never throws), and a derived card is a working card. It warns so the
 * count is visible; it never fails the exit code. A custom provider is CALLED to learn whether it declines
 * — the only way to know — inside a guard, so a provider that throws is a warning line naming the
 * exception, not a doctor that stops.
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
        $derived = [];
        $absent = [];

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
                    continue; // opted out, or neither in the rail nor declared: not on this dashboard
                }

                $binding = DashboardParticipation::declares($definition);
                $name = sprintf('[%s/%s]', $realm, $key);
                $custom = $this->customProvider($resource);

                if (! $custom && ! $this->query->queryable($definition)) {
                    $absent[$name] = $name.' (default provider over a backing that cannot count)';

                    continue;
                }

                if ($custom) {
                    $declines = $this->declines($definition);

                    if ($declines !== null) {
                        $absent[$name] = $name.' ('.$declines.')';

                        continue;
                    }
                }

                if (! $binding && ! $custom) {
                    $derived[$name] = $name.' (in the rail'.($resource->section !== null ? ' under ['.$resource->section.']' : '').', one `total` figure)';

                    continue;
                }

                $declared++;
            }
        }

        $total = $declared + count($derived) + count($absent);

        if ($total === 0) {
            return [Finding::inconclusive(self::CHECK, 'No realm resource is on any dashboard here — nothing is in a realm\'s rail or declares a summary.')];
        }

        $findings = [];

        if ($derived !== []) {
            ksort($derived);

            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%d of %d dashboard card%s rest%s on the DERIVED tier — in the rail, no `summary`/`overview` binding, default provider: %s. '
                .'Each draws one `total` figure nobody chose. Declare `#[Summary]`/`#[Overview]` on the read Data class, name a `summaryProvider:`, or opt out with `#[Summary(false)]`.',
                count($derived),
                $total,
                $total === 1 ? '' : 's',
                count($derived) === 1 ? 's' : '',
                implode('; ', $derived),
            ));
        }

        if ($absent !== []) {
            ksort($absent);

            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%d of %d dashboard card%s %s ABSENT — the resource is on the dashboard and its provider cannot answer, so the card is dropped: %s. '
                .'Name a `summaryProvider:` that can summarize the backing, or opt out with `#[Summary(false)]`.',
                count($absent),
                $total,
                $total === 1 ? '' : 's',
                count($absent) === 1 ? 'is' : 'are',
                implode('; ', $absent),
            ));
        }

        if ($findings !== []) {
            return $findings;
        }

        return [Finding::pass(self::CHECK, sprintf(
            '%d dashboard card%s, every one on the declared tier (a `summary`/`overview` binding or a custom provider).',
            $total,
            $total === 1 ? '' : 's',
        ))];
    }

    private function customProvider(ParticleResource $resource): bool
    {
        return $resource->summaryProvider !== null && $resource->summaryProvider !== BeamResourceSummaryProvider::class;
    }

    /** Why a custom provider yields no card — null when it answers. */
    private function declines(ResourceDefinition $definition): ?string
    {
        try {
            $provider = $this->container->make($definition->summaryProvider);

            if (! $provider instanceof ResourceSummaryProvider) {
                return 'summary provider '.$definition->summaryProvider.' does not implement ResourceSummaryProvider';
            }

            return $provider->summary($definition) === null ? 'custom provider declines' : null;
        } catch (Throwable $e) {
            return 'custom provider threw '.$e::class;
        }
    }
}
