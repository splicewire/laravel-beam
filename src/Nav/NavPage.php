<?php

namespace Splicewire\Beam\Nav;

use LogicException;

/**
 * A host PAGE SEAT (app-walkthrough M4′, APP-1, APP-15): one bespoke, resource-less page declared ONCE, so the same
 * declaration feeds its RouteContext leaf and its nav row.
 *
 * ## The gap this closes
 *
 * A bespoke page (a desk, a lens, a catalog with no frame resource behind it) used to be spelled twice in a host: once as
 * a standalone RouteContext entry (`routeName`, `path`, `shell`) and once as a hand nav row (`title`, `href`, `icon`,
 * `routeName`). The two drifted silently, because nothing related the row's `href` to the leaf's `path`. Under OQ-A4 the
 * seat carries its route, so there is one place to move a page and its nav follows.
 *
 * ## No `href` slot, on purpose
 *
 * The row's `href` is MINTED from the leaf by the host's `RouteContextProjector::hrefs($realm)` and handed to
 * {@see navRow()}; it is never a constructor argument, so it cannot be authored. A page whose leaf mints no href (it is not
 * in that realm's RouteContext) refuses rather than spelling one, which is the drift this type exists to end.
 *
 * ## No defaults
 *
 * Like {@see NavSection}'s gate slots and audience, every slot is required. `shell: 'app'` and `guard: null` are host
 * decisions on the record (flat under the app shell; inherit the realm's guard), and an absent `navOrder` (sorts last) is
 * a different claim from `0` (leads its section). A `null` `section` would be a page with no nav seat; that is not this
 * type, so `section` is a string.
 */
class NavPage
{
    /**
     * @param  string  $realm  the realm whose RouteContext emits the leaf and whose navigation seats the row
     * @param  string  $routeName  the leaf's stable identity and the nav join key
     * @param  string  $path  the leaf's path, relative to its shell's mount under the realm base
     * @param  string  $shell  the hand-written layout the leaf nests under (`app` = flat)
     * @param  string  $mounts  what the leaf renders (`detail`, `list`, `widget`)
     * @param  string|null  $guard  the leaf's guard, or `null` to inherit the realm's
     * @param  string  $section  the section key whose seat lists this page as a child row
     * @param  string  $label  the row's title
     * @param  string  $icon  the icon name the host's icon set resolves
     * @param  int|null  $navOrder  the row's place among its section's children; `null` sorts last
     * @param  NavAudience  $audience  who the page is for (IA-8): a `developer` page is drawn in the Developer zone
     */
    public function __construct(
        public readonly string $realm,
        public readonly string $routeName,
        public readonly string $path,
        public readonly string $shell,
        public readonly string $mounts,
        public readonly ?string $guard,
        public readonly string $section,
        public readonly string $label,
        public readonly string $icon,
        public readonly ?int $navOrder,
        public readonly NavAudience $audience,
    ) {}

    /**
     * The RouteContext standalone entry, in the shape `RouteContextPlan::$centralStandalone` / `$scopedStandalone` take.
     *
     * @return array{routeName: string, path: string, mounts: string, guard: ?string, shell: string}
     */
    public function standalone(): array
    {
        return [
            'routeName' => $this->routeName,
            'path' => $this->path,
            'mounts' => $this->mounts,
            'guard' => $this->guard,
            'shell' => $this->shell,
        ];
    }

    /**
     * The nav row, its `href` taken from the realm's minted hrefs.
     *
     * @param  array<string, string>  $hrefs  routeName => absolute client path, from `RouteContextProjector::hrefs($realm)`
     * @return array{title: string, href: string, icon: string, routeName: string, navOrder?: int}
     *
     * @throws LogicException when the leaf mints no href: the page is declared but its realm does not emit it
     */
    public function navRow(array $hrefs): array
    {
        if (! isset($hrefs[$this->routeName])) {
            throw new LogicException(sprintf(
                'Page seat [%s] in realm [%s] has no minted href: its leaf is not in that realm\'s RouteContext. A page seat\'s href is minted, never spelled.',
                $this->routeName,
                $this->realm,
            ));
        }

        $row = ['title' => $this->label, 'href' => $hrefs[$this->routeName], 'icon' => $this->icon, 'routeName' => $this->routeName];

        return $this->navOrder === null ? $row : [...$row, 'navOrder' => $this->navOrder];
    }

    /**
     * One realm's standalone entries, in declared order.
     *
     * @param  iterable<self>  $pages
     * @return list<array{routeName: string, path: string, mounts: string, guard: ?string, shell: string}>
     */
    public static function standaloneFor(iterable $pages, string $realm): array
    {
        $entries = [];
        foreach ($pages as $page) {
            if ($page->realm === $realm) {
                $entries[] = $page->standalone();
            }
        }

        return $entries;
    }

    /**
     * One section's rows in one realm, each with its minted href.
     *
     * @param  iterable<self>  $pages
     * @param  array<string, string>  $hrefs  routeName => absolute client path for that realm
     * @return list<array{title: string, href: string, icon: string, routeName: string, navOrder?: int}>
     */
    public static function rowsFor(iterable $pages, string $realm, string $section, array $hrefs): array
    {
        $rows = [];
        foreach ($pages as $page) {
            if ($page->realm === $realm && $page->section === $section) {
                $rows[] = $page->navRow($hrefs);
            }
        }

        return $rows;
    }
}
