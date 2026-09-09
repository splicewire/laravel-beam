<?php

namespace Splicewire\Beam\Nav;

/**
 * The soft-lock a {@see NavSection} declares alongside its entitlement gate — the seat's answer to
 * *"what should an UNENTITLED principal see?"*
 *
 * ## What it changes about an entitlement gate
 *
 * A seat's `entitlement` list is an any-of gate. Without a lock, failing it is HARD: the seat is
 * omitted, and an unentitled principal never learns the section exists. That is right for protection
 * and wrong for monetization — a plan-gated section a user cannot discover cannot be sold. Declaring
 * a lock makes the same gate SOFT: the seat still projects, carrying a wire-visible
 * `Rushing\DataNav\NavLocked` the rail renders as a locked row with an upsell.
 *
 * The two outcomes are exclusive per seat, and hard is the default — an existing seat that declares
 * no lock is byte-for-byte unchanged. This mirrors {@see \Splicewire\Beam\Realm\RealmManifestProjector}
 * one plane down: a realm gate's `mode` defaults to `'hard'` and opts into `'soft'`, producing the
 * same `locked` + `upsell` descriptor pair for a realm tile that this produces for a nav row.
 *
 * ## It locks the ENTITLEMENT plane only
 *
 * The two authorization planes are orthogonal and a seat may declare both. A lock is the ENTITLEMENT
 * answer — *"your plan does not include this"* — so a soft-gated seat still runs its `permission`
 * gate, and a principal who fails THAT is omitted as before. Locking on the permission plane would
 * show an upsell to a user whose organisation already pays, which is the one outcome worse than
 * hiding the seat.
 *
 * ## A plain value object, for the same reason {@see NavSection} is
 *
 * It carries no `Rushing\DataNav` type. beam does not depend on `rushing/laravel-data-nav`, and the
 * packages that declare seats (`laravel-beam-calendars`, `-notifications`, `-workflows`) require only
 * beam. Turning this into a `NavLocked` on a nav node is
 * {@see \Splicewire\Beam\Ux\Frame\NavSectionProjector}'s job, one tier up — the same split the seat
 * itself already uses.
 */
final class NavSeatLock
{
    /**
     * @param  string  $reason  the human-facing sentence shown on the locked row (e.g. "Available on
     *                          the Songwriter plan"). Required: a lock with nothing to say is a
     *                          disabled row, not an upsell
     * @param  string|null  $upsell  an OPAQUE host token the renderer maps to an upgrade action — a
     *                               plan key, an upgrade href, a feature id. Neither beam nor the rail
     *                               interprets it. Null is legitimate: a lock may state a reason with
     *                               no actionable upgrade path ("Coming soon")
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?string $upsell = null,
    ) {}
}
