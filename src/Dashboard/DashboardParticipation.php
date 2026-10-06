<?php

namespace Splicewire\Beam\Dashboard;

use ReflectionClass;
use Schemastud\Frame\Registry\ResourceDefinition;
use Schemastud\Frame\Registry\WidgetContextProjector;
use Splicewire\Beam\Particle\ListRouteName;
use Splicewire\Beam\Summary\BeamResourceSummaryProvider;
use Throwable;

/**
 * Whether a realm resource is ON the realm's dashboard, and in which context — the actor-free half of
 * the rule, written once (realm-dashboards ticket 04 review).
 *
 * A declared summary/overview (or a seated custom summary provider) makes a card; rail presence
 * alone makes a tile. Summary(false) opts out of cards. Developer-zone leaves never participate.
 * Overview wins over summary. This is the IA-16 / OQ-3 floor (UX-14), shared with the doctor audit.
 *
 * The actor-DEPENDENT half — may this actor list the resource, is its list route mounted here, does its
 * provider answer — stays with beam-ux's `DashboardBacking`. The doctor's `DashboardTierAudit` reads only
 * this half, because a tier is a fact about the declaration, not about who is looking.
 */
final class DashboardParticipation
{
    public const CONTEXT_SUMMARY = 'summary';

    public const CONTEXT_OVERVIEW = 'overview';

    /**
     * @param  string|null  $href  the resource's mounted list href when the caller knows it; the rail
     *                             is matched by route name first, so this is the fallback join
     * @param  NavLeaf|null  $seat  OUT: the rail leaf this resource matched, or null when no leaf does
     *                              (a resource that participates only by declaring `summary`/`overview`,
     *                              or one that does not participate at all). Returned rather than left
     *                              to the caller so a caller that needs the resource's RAIL POSITION —
     *                              beam-ux's `DashboardBacking`, which orders a card with no declared
     *                              `navOrder` by it — reads the match this rule already made instead of
     *                              walking the rail a second time and risking a different answer.
     * @return 'summary'|'overview'|null
     */
    public static function contextFor(ResourceDefinition $definition, RailLeaves $rail, ?string $href = null, ?NavLeaf &$seat = null): ?string
    {
        $seat = null;

        try {
            $contexts = (new WidgetContextProjector)->forClass(new ReflectionClass($definition->data));
        } catch (Throwable) {
            return null; // a declaration frame's projector rejects is another audit's finding
        }

        $summary = $contexts[self::CONTEXT_SUMMARY] ?? null;
        $overview = $contexts[self::CONTEXT_OVERVIEW] ?? null;

        if ($summary !== null && ($summary['participates'] ?? true) === false) {
            return null; // opted out: no context, and no seat either — it is on no dashboard
        }

        // Matched even when a declaration decides the context below: a resource that declares
        // `summary`/`overview` AND sits in the rail still has a rail position, and a caller ordering by
        // it must get the same number the tile for that leaf gets.
        $seat = $rail->find(ListRouteName::of($definition), $href);

        if ($seat?->developer) {
            return null;
        }

        if ($overview !== null && ($overview['participates'] ?? true) !== false) {
            return self::CONTEXT_OVERVIEW;
        }

        if ($summary !== null) {
            return self::CONTEXT_SUMMARY;
        }

        // A custom provider is an explicit summary declaration; rail presence alone is only a tile.
        return $seat !== null && $definition->summaryProvider !== null
            && $definition->summaryProvider !== BeamResourceSummaryProvider::class
                ? self::CONTEXT_SUMMARY : null;
    }

    /**
     * Whether the read Data class binds a dashboard context ITSELF — participates with no rail at all.
     * The "declared" tier's first test, distinct from being seated; an opt-out reads false here too.
     */
    public static function declares(ResourceDefinition $definition): bool
    {
        return self::contextFor($definition, new RailLeaves([])) !== null;
    }
}
