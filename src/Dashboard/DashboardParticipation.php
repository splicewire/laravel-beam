<?php

namespace Splicewire\Beam\Dashboard;

use ReflectionClass;
use Schemastud\Frame\Registry\ResourceDefinition;
use Schemastud\Frame\Registry\WidgetContextProjector;
use Splicewire\Beam\Particle\ListRouteName;
use Throwable;

/**
 * Whether a realm resource is ON the realm's dashboard, and in which context — the actor-free half of
 * the rule, written once (realm-dashboards ticket 04 review).
 *
 * **A realm resource participates by default iff a leaf of the realm's rail resolves to it (by its list
 * route name, else by href), or it declares `summary`/`overview` explicitly; `#[Summary(false)]` opts
 * out regardless.** `overview` is chosen when declared, else `summary`.
 *
 * "Nav-seated" used to mean "declares `section:` and a package seated that section here". At the beam
 * starter, `users` and `teams` declare no `section:` and reach the rail as a seat's STATIC children —
 * so the old reading yielded zero cards on the reference host. The rail is what a user sees, so the
 * rail is the definition; which rail (the projected tree for an actor, or the declared rail actor-free)
 * is the caller's choice — see {@see RailLeaves}.
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
     * @return 'summary'|'overview'|null
     */
    public static function contextFor(ResourceDefinition $definition, RailLeaves $rail, ?string $href = null): ?string
    {
        try {
            $contexts = (new WidgetContextProjector)->forClass(new ReflectionClass($definition->data));
        } catch (Throwable) {
            return null; // a declaration frame's projector rejects is another audit's finding
        }

        $summary = $contexts[self::CONTEXT_SUMMARY] ?? null;
        $overview = $contexts[self::CONTEXT_OVERVIEW] ?? null;

        if ($summary !== null && ($summary['participates'] ?? true) === false) {
            return null;
        }

        if ($overview !== null && ($overview['participates'] ?? true) !== false) {
            return self::CONTEXT_OVERVIEW;
        }

        if ($summary !== null) {
            return self::CONTEXT_SUMMARY;
        }

        return $rail->find(ListRouteName::of($definition), $href) !== null ? self::CONTEXT_SUMMARY : null;
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
