<?php

namespace Splicewire\Beam\Dashboard;

use Splicewire\Beam\Doctor\DashboardTierAudit;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\ListRouteName;

/**
 * The naming grammar of the read-only overview a flagged {@see NavSection} gets (ADR-0222 decision 9), written once so
 * beam-ux's registration, backing, nav seat and route leaf, and beam's own {@see DashboardTierAudit}, cannot spell the
 * key, the route name or the ability differently. It sits here for the reason {@see RealmDashboard} does: the audit has
 * to recognise the resource by name, and beam cannot see beam-ux.
 *
 * ## Why the key carries the realm and the section
 *
 * The same section key may be seated in two realms, and frame's resource socket is realm-blind, so each seat needs a
 * resource of its own. A realm and a section are both needed to name it.
 *
 * ## Why {@see isKey()} asks the registry
 *
 * A resource may be named `operator-reports-overview` without being one. Only a seat flagged in that realm makes the key
 * an overview, so the name alone is not the answer.
 */
final class SectionOverview
{
    public static function keyFor(string $realm, string $section): string
    {
        return $realm.'-'.$section.'-overview';
    }

    public static function routeNameFor(string $realm, string $section): string
    {
        return ListRouteName::for(self::keyFor($realm, $section));
    }

    /** The Gate ability the overview declares as its `policy:`. Beam-ux defines it unless a host did first. */
    public static function abilityFor(string $realm, string $section): string
    {
        return 'section-overview.view.'.$realm.'.'.$section;
    }

    public static function isKey(string $key, string $realm, NavSectionRegistry $sections): bool
    {
        return self::sectionFor($key, $realm, $sections) !== null;
    }

    /** The flagged seat whose overview this key names, or null. */
    public static function sectionFor(string $key, string $realm, NavSectionRegistry $sections): ?NavSection
    {
        foreach (self::flagged($realm, $sections) as $section) {
            if ($key === self::keyFor($realm, $section->key)) {
                return $section;
            }
        }

        return null;
    }

    /**
     * The realm's seats that have an overview, in projection order. Empty is the common case, and every caller's new
     * branch is inert on it, which is what keeps an unflagged host's output byte-identical.
     *
     * @return list<NavSection>
     */
    public static function flagged(string $realm, NavSectionRegistry $sections): array
    {
        return array_values(array_filter($sections->for($realm), fn (NavSection $section): bool => $section->overview));
    }

    /**
     * The realm-relative path the overview's leaf mounts at: the seat's own href without the realm's base, so the seat
     * and the leaf are one URL. A seat href outside the base (`/calendars`, declared for every realm) keeps its path.
     */
    public static function pathFor(NavSection $section, string $routeBase): string
    {
        $base = '/'.trim($routeBase, '/');
        $href = '/'.trim($section->href, '/');

        if ($base !== '/' && ($href === $base || str_starts_with($href, $base.'/'))) {
            $href = substr($href, strlen($base));
        }

        return trim($href, '/');
    }
}
