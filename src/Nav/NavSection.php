<?php

namespace Splicewire\Beam\Nav;

/**
 * A package-declared top-level NAV SEAT — one section a realm's navigation shows at its top level.
 *
 * ## The gap this closes
 *
 * A `#[ParticleResource(section: 'ops')]` declaration says which section a resource belongs UNDER. It
 * does not, and cannot, bring that section into being: a host's navigation collects declared resources
 * as CHILDREN of a section seat the host itself hand-authored. Measured 2026-09-05 across the family:
 * 9 packages declare 30 sections that way, and 11 of the 30 (`ops` ×6, `calendars` ×3, `authoring` ×2)
 * name a section no host seats — correctly declared, and invisible. Packages could contribute children
 * and could not seat a section. This is the declaration site for the missing half.
 *
 * The rule it satisfies (api-surface-coherence issue 142, closing rule): *"A fact is declarable when the
 * declaring party is the one that knows it. When only the host can know it, a list is the honest form —
 * and the list must compose."* A package knows its own section's contents, label and icon, so those are
 * declarable here. **Ordering and override remain the host's** — `order` below is the declarer's
 * PREFERENCE, and a host projector is free to ignore it. Issue 142's verdict table leaves nav
 * "not decided here"; this is new ground rather than a reversal of it.
 *
 * ## A plain value object, deliberately
 *
 * This carries no `Rushing\DataNav` type and beam takes no dependency on `rushing/laravel-data-nav`
 * (verified: zero `data-nav` hits in beam's `composer.json`). The three packages that need to seat a
 * section — `splicewire/laravel-beam-calendars`, `-notifications`, `-workflows` — require ONLY
 * `splicewire/laravel-beam`, so the declaration site must live here and must stay free of the nav
 * library. Turning a seat into an `InvocableNavItem` is a beam-UX projector's job, one tier up.
 *
 * The field set is modelled on what the flagship's own seat-builder can already express
 * (`~/Herd/splicewire-app/app/Navigation/AppNavigation.php`, the private `section()` helper) — realm,
 * key, label, icon, href, an any-of `entitlement` list, a single `permission` token, and hand-authored
 * `static` child rows. A declared seat must be able to say everything that helper says, or the flagship
 * could not be expressed as declarations.
 *
 * ## Why the gate slots have no defaults
 *
 * `entitlement` and `permission` are REQUIRED parameters that happen to be nullable. "I chose not to
 * gate this section" and "I forgot to think about gating" must not be spelled the same way, and a
 * `= null` default spells them identically. Passing `entitlement: null` is a decision on the record.
 *
 * For the same reason `null` and `[]` are different values here and neither is normalised into the
 * other: `entitlement: null` is UNGATED (always visible), `entitlement: []` is an any-of list with no
 * members — a gate that was declared and admits nobody. {@see isEntitlementGated()} tells them apart.
 */
class NavSection
{
    /**
     * @param  string  $key  the section string a `#[ParticleResource(section:)]` declaration joins on —
     *                       resources naming this section become this seat's children
     * @param  string  $realm  the region this seat belongs to (`tenant`, `operator`, …). A seat for a
     *                         realm the host has not registered is a silent no-op — see
     *                         {@see NavSectionRegistry}
     * @param  string  $label  the human title rendered on the seat
     * @param  string  $icon  the icon name the host's icon set resolves (e.g. `CalendarDays`)
     * @param  string  $href  the seat's own destination
     * @param  int  $order  the declarer's PREFERRED placement, ascending. Required — an absent
     *                      preference and a preference of zero are different claims. Ties are broken
     *                      by `key`, so the projected order is total and deterministic regardless
     *                      of registration order. Advisory: the host may reorder
     * @param  list<string>|null  $entitlement  any-of capability keys — holding ONE reveals the seat.
     *                                          `null` = ungated. `[]` = gated and unsatisfiable
     * @param  string|null  $permission  a single RBAC token, or `null` for ungated
     * @param  list<array{title: string, href: string, icon?: string, routeName?: string, navOrder?: int}>  $static
     *                                                                                                               hand-authored child rows, in the same shape the flagship
     *                                                                                                               already passes through `section(static: [...])`. They merge
     *                                                                                                               into one ordering with the auto-attached resource children
     *                                                                                                               host-side; a row with no `navOrder` sorts last
     */
    public function __construct(
        public readonly string $key,
        public readonly string $realm,
        public readonly string $label,
        public readonly string $icon,
        public readonly string $href,
        public readonly int $order,
        public readonly ?array $entitlement,
        public readonly ?string $permission,
        public readonly array $static = [],
    ) {}

    /**
     * Whether an entitlement gate was DECLARED — true for `[]` as much as for a populated list, false
     * only for `null`. The distinction the constructor docblock argues, made readable.
     */
    public function isEntitlementGated(): bool
    {
        return $this->entitlement !== null;
    }

    /** Whether a permission gate was DECLARED. */
    public function isPermissionGated(): bool
    {
        return $this->permission !== null;
    }

    /** Whether either gate axis was declared at all. */
    public function isGated(): bool
    {
        return $this->isEntitlementGated() || $this->isPermissionGated();
    }

    /**
     * The declared gate vocabulary, with UNDECLARED axes absent and declared-but-empty ones present.
     *
     * Mirrors the flagship's `array_filter(..., fn ($value) => $value !== null)` exactly — `[]` survives,
     * `null` drops — so a projector can hand this straight to a gate-meta bag and an ungated seat
     * carries no gate keys at all rather than carrying null-valued ones.
     *
     * @return array{entitlement?: list<string>, permission?: string}
     */
    public function gate(): array
    {
        return array_filter(
            ['entitlement' => $this->entitlement, 'permission' => $this->permission],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * The total, deterministic comparison two seats are ordered by: declared `order` ascending, ties
     * broken by `key`. Lives here rather than in the registry so a projector that assembles seats from
     * more than one source orders them the same way beam does.
     */
    public static function compare(self $a, self $b): int
    {
        return [$a->order, $a->key] <=> [$b->order, $b->key];
    }
}
