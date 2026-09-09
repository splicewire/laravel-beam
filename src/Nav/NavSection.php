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
 *
 * ## Why `$lock` DOES have a default, when the gate slots do not
 *
 * `$lock` is the one slot below that defaults, and the reason is that it is not a third gate axis.
 * `entitlement` and `permission` decide WHETHER a seat is gated, and there is no behaviour-preserving
 * answer to that question — which is exactly why they may not default. `$lock` decides how an
 * ALREADY-DECLARED entitlement gate FAILS, and that question has a settled answer: hard, i.e. the
 * omission every existing seat already gets. Defaulting it is what makes this addition inert, and it
 * matches the plane above — {@see \Splicewire\Beam\Realm\RealmManifestProjector} reads a realm gate's
 * `mode` as `$gate['mode'] ?? 'hard'`.
 *
 * A lock on a seat that declares NO entitlement is meaningless and is treated as such: there is no
 * gate for it to soften, so the seat projects ungated and unlocked. {@see isSoftGated()} is the pair
 * of conditions, not either one alone.
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
     * @param  NavSeatLock|null  $lock  softens this seat's ENTITLEMENT gate: an unentitled principal
     *                                  gets the seat present-but-locked with this reason/upsell
     *                                  instead of omitted. `null` (the default) = hard, today's
     *                                  behaviour. See the class docblock for why this one defaults
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
        public readonly ?NavSeatLock $lock = null,
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
     * Whether this seat's entitlement gate is SOFT — an unentitled principal sees it locked rather
     * than not at all.
     *
     * BOTH conditions are required, and that is the whole point of the method. A lock with no
     * entitlement gate has nothing to soften, and an entitlement gate with no lock is hard. Spelling
     * the conjunction once here is what stops a projector from reading a stray `lock:` on an ungated
     * seat as a reason to lock a section nobody gated.
     */
    public function isSoftGated(): bool
    {
        return $this->lock !== null && $this->isEntitlementGated();
    }

    /**
     * The gate vocabulary MINUS the axis a lock has taken over — what a soft-gated seat's meta bag
     * should carry.
     *
     * A soft seat must not also carry its `entitlement` key into the gate meta: a host that has
     * registered an entitlement gate stage would read that key and OMIT the very node the lock exists
     * to keep visible, and the lock would never reach the wire. The permission key is untouched,
     * because the planes are orthogonal — a soft-gated seat still disappears for a principal who
     * lacks the RBAC token, which is the denial a lock must never be confused with.
     *
     * Identical to {@see gate()} for a hard or ungated seat, so this is the only gate read a
     * projector needs.
     *
     * @return array{entitlement?: list<string>, permission?: string}
     */
    public function gateAfterLock(): array
    {
        $gate = $this->gate();

        if ($this->isSoftGated()) {
            unset($gate['entitlement']);
        }

        return $gate;
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
