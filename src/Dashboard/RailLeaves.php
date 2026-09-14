<?php

namespace Splicewire\Beam\Dashboard;

use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Particle\ListRouteName;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The leaves of one realm's rail, in rail order — the set a resource is matched against to decide
 * whether it is "nav-seated" ({@see DashboardParticipation}).
 *
 * Two readings, one shape:
 *
 *  - {@see fromNavItems()} walks a PROJECTED navigation (`FrameNavContribution::navigation($realm)`
 *    serialized — the same tree the rail renders for the actor in hand). This is what the dashboard
 *    backing reads: it has the actor, so it may read the gated tree.
 *  - {@see declaredFor()} reads the DECLARED rail actor-free: every seat a package registered for the
 *    realm, the resources that auto-attach to it by `section:`, and the seat's hand-authored static
 *    children. This is what the doctor audit reads: it has no actor, and a gated tree read as a guest
 *    would hide every model-less resource — exactly the population the audit exists to name. A host
 *    that supersedes the declared navigation wholesale is measured by the backing at request time,
 *    not by the audit.
 */
final class RailLeaves
{
    /** @param  list<NavLeaf>  $leaves */
    public function __construct(
        public readonly array $leaves,
    ) {}

    /**
     * Walk a serialized nav tree (`NavTree::toArray()['items']`) depth-first; a leaf is a node with an
     * href and no children. Section headers draw nothing.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public static function fromNavItems(array $items): self
    {
        $leaves = [];

        $walk = function (array $nodes) use (&$walk, &$leaves): void {
            foreach ($nodes as $node) {
                $children = is_array($node['children'] ?? null) ? $node['children'] : [];

                if ($children !== []) {
                    $walk($children);

                    continue;
                }

                $href = $node['href'] ?? null;

                if (! is_string($href) || $href === '') {
                    continue;
                }

                $routeName = $node['routeName'] ?? null;

                $leaves[] = new NavLeaf(
                    index: count($leaves),
                    href: $href,
                    routeName: is_string($routeName) ? $routeName : null,
                    title: (string) ($node['title'] ?? $href),
                    icon: is_string($node['icon'] ?? null) ? $node['icon'] : null,
                );
            }
        };

        $walk($items);

        return new self($leaves);
    }

    /**
     * The declared rail, actor-free: for each seat in the realm (registry order), its auto-attached
     * resources (those declaring `section:` = the seat's key and registered in the realm, by list route
     * name) and its static children. Hrefs are not derived here — beam has no router projection — so a
     * resource child's href is its route name's placeholder and matching is by route name.
     */
    public static function declaredFor(string $realm, NavSectionRegistry $sections, ParticleResourceRegistry $particles): self
    {
        $leaves = [];
        $keys = $particles->keysForRealm($realm);

        foreach ($sections->for($realm) as $seat) {
            /** @var NavSection $seat */
            foreach ($keys as $key) {
                $resource = $particles->find($key);

                if ($resource === null || $resource->section !== $seat->key) {
                    continue;
                }

                $routeName = ListRouteName::of($resource);
                $leaves[] = new NavLeaf(count($leaves), $routeName, $routeName, $resource->label, $resource->icon);
            }

            foreach ($seat->static as $child) {
                $href = (string) ($child['href'] ?? '');

                if ($href === '') {
                    continue;
                }

                $leaves[] = new NavLeaf(
                    count($leaves),
                    $href,
                    isset($child['routeName']) ? (string) $child['routeName'] : null,
                    (string) ($child['title'] ?? $href),
                    isset($child['icon']) ? (string) $child['icon'] : null,
                );
            }
        }

        return new self($leaves);
    }

    /** The first leaf naming this route, else the first at this href, else null. */
    public function find(?string $routeName, ?string $href = null): ?NavLeaf
    {
        foreach ($this->leaves as $leaf) {
            if ($routeName !== null && $leaf->routeName === $routeName) {
                return $leaf;
            }
        }

        foreach ($this->leaves as $leaf) {
            if ($href !== null && $leaf->href === $href) {
                return $leaf;
            }
        }

        return null;
    }
}
