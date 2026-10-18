<?php

namespace Splicewire\Beam\Realm;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Splicewire\Beam\Entitlements\EntitlementGate;
use Splicewire\Beam\Ia\RealmProfiles;

/**
 * Frame OS ticket 08 (ADR-0013 §4/§5): projects the registered realms into the manifest the frame reads,
 * applying a per-realm entitlement gate with two styles:
 *
 *  - **hard → absence.** An unentitled principal does NOT see the realm at all: its descriptor is OMITTED
 *    from the projected manifest, so protection is by construction (the launcher can't render what isn't
 *    there).
 *  - **soft → locked flag.** An unentitled principal STILL sees the realm, but its descriptor carries
 *    `locked: true` + the `upsell` metadata, so a launcher can render it as lockable (monetization).
 *
 * An UNGATED realm (no profile `gate` in `beam.core.realms.{key}` and no deprecated `beam.core.realm_gates` entry)
 * always projects, unlocked. The gating rides beam
 * config, NOT the shared `RealmDefinition` DTO (which carries no gating field — it is a schemastud shape).
 *
 * Descriptor shape — a plain, JSON-serializable array (ticket 11 wraps it in a laravel-data-nav NavNode;
 * ticket 13 launches over it — both consume this shape, so it stays simple):
 *   [ 'key' => string, 'title' => string, 'routeBase' => string, 'locked' => bool, 'upsell' => array|null ]
 *
 * Frame OS ticket 14 (ADR-0014 §A2): BEFORE a realm's descriptor is appended to the manifest, the
 * projector folds in any additive OVERLAYS registered for that realm ({@see RealmOverlayRegistry}) — a
 * `schemastud/laravel-data-schemas` data-schema fold that adds fields/capabilities/entries/overrides,
 * plus entitlement-gated capabilities that SOFT-LOCK (present + upsell) when the principal is unheld.
 * Resolution is wholly PHP-side, before emit, so the frame stays overlay-agnostic (it sees only realms,
 * some enriched, indistinguishable in kind). An overlay only enriches a realm the {@see RealmRegistry}
 * ALREADY ships — the projector iterates the realm registry, never the overlay registry, so an overlay
 * can never conjure a realm/tile into being. Inert by default: no registered overlay ⇒ the manifest is
 * byte-for-byte unchanged.
 *
 * Inert by default: an empty `realm_gates` gates nothing, so every realm projects unlocked and an existing
 * host is byte-for-byte.
 *
 * Each realm's gate is asked of the principal THAT REALM belongs to (L3 ticket 10a, orch-lead 13:20Z). Hosts read realms
 * inside the tenant boundary, where the request's principal is the user's tenant SEAT. A central realm
 * (`RealmDefinition::$central`, the operator preset) belongs to the central user behind that seat, whose reach is
 * granted centrally, so asking the seat omitted Operator for a fresh Tower's owner. Every other realm stays on the
 * principal as given, so tenant admission never widens to the central user's grants.
 */
class RealmManifestProjector
{
    /** A tenant seat whose central user cannot be found: it belongs to no central principal, so no central gate admits. */
    private const NO_CENTRAL_PRINCIPAL = "\0no-central-principal";

    private RealmOverlayRegistry $overlays;

    public function __construct(
        private RealmRegistry $realms,
        private EntitlementGate $entitlements,
        ?RealmOverlayRegistry $overlays = null,
    ) {
        // Nullable + default so the pre-ticket-14 two-arg construction (and existing tests) still work;
        // an unbound registry is an EMPTY one, which folds nothing (inert).
        $this->overlays = $overlays ?? new RealmOverlayRegistry;
    }

    /**
     * Project the realm manifest for a principal.
     *
     * @return list<array<string, mixed>>
     */
    public function project(mixed $principal): array
    {
        $profiles = new RealmProfiles;
        $manifest = [];

        foreach ($this->realms->all() as $key => $realm) {
            // The realm profile's gate (ux-walkthrough M1), else the deprecated `realm_gates` alias entry.
            $gate = $profiles->gate($key);

            // The principal this realm belongs to. A seat whose central user cannot be found has none (fail closed);
            // a null principal stays null, which the resolver reads as "the request's scope", as before.
            $subject = $realm->central ? $this->centralPrincipal($principal) : $principal;
            $entitled = $gate === null
                || ($subject !== self::NO_CENTRAL_PRINCIPAL && $this->admits((string) ($gate['entitlement'] ?? ''), $subject));

            $mode = $gate['mode'] ?? 'hard';

            // hard-gated + not entitled → OMIT entirely (protection by construction).
            if ($gate !== null && ! $entitled && $mode === 'hard') {
                continue;
            }

            // soft-gated + not entitled → PRESENT, carrying locked + upsell (monetization).
            $locked = $gate !== null && ! $entitled && $mode === 'soft';

            $descriptor = [
                'key' => $key,
                'title' => (string) ($gate['title'] ?? $this->titleFor($key)),
                'routeBase' => $realm->routeBase,
                'locked' => $locked,
                'upsell' => $locked ? ($gate['upsell'] ?? null) : null,
            ];

            // Ticket 14: fold any additive satellite/capability overlays onto THIS realm's descriptor,
            // PHP-side, before it is appended (i.e. before the manifest emits). Only realms the registry
            // ships reach here, so an overlay never manufactures a realm.
            $manifest[] = $this->applyOverlays($key, $descriptor, $subject === self::NO_CENTRAL_PRINCIPAL ? null : $subject);
        }

        return $manifest;
    }

    /**
     * Fold every overlay registered for `$realmKey` onto the descriptor: first the data-schema field
     * enrichment, then the entitlement-gated capabilities (soft-locked per the principal). Returns the
     * descriptor UNCHANGED when no overlay targets the realm (the common case — inert).
     *
     * @param  array<string, mixed>  $descriptor
     * @return array<string, mixed>
     */
    private function applyOverlays(string $realmKey, array $descriptor, mixed $principal): array
    {
        $overlays = $this->overlays->for($realmKey);

        if ($overlays === []) {
            return $descriptor;
        }

        foreach ($overlays as $overlay) {
            // (1) the data-schema overlay fold — adds/overrides descriptor fields (fields, entries, …).
            $descriptor = $overlay->enrich($descriptor);

            // (2) entitlement-gated capabilities — appended present-but-locked when the principal is
            //     unentitled (same soft-lock shape as a soft-gated realm), unlocked when entitled.
            foreach ($overlay->capabilities as $capability) {
                $entitled = $capability->entitlement === null
                    || $this->entitlements->allows($capability->entitlement, $principal);

                $descriptor['capabilities'][] = $capability->project($entitled);
            }
        }

        return $descriptor;
    }

    /**
     * Whether the principal clears a realm gate's entitlement, asked as the door asks it (ux-walkthrough UX-12a): the
     * `entitlement:{key}` Gate ability {@see RealmGateAbility} names, for this principal, so a host's `Gate::before`
     * (the flagship admits Root) reaches the nav too and the nav never offers a realm the door refuses, or hides one
     * it admits. Where the host defines no ability for the key, the resolver still answers, as before, and
     * `Gate::before` may add to it. A principal that is not a user (a guest, or a scope such as the tower starter's
     * PrincipalScope) passes no door and may not reach a host's `Gate::before`, so the resolver alone answers for it.
     */
    private function admits(string $entitlement, mixed $principal): bool
    {
        if (! $principal instanceof Authorizable) {
            return $this->entitlements->allows($entitlement, $principal);
        }

        $ability = 'entitlement:'.$entitlement;
        $gate = Gate::forUser($principal);

        if (Gate::has($ability)) {
            return $gate->allows($ability);
        }

        return $this->entitlements->allows($entitlement, $principal) || $gate->allows($ability);
    }

    /**
     * The central principal behind `$principal`. A tenant seat in stancl's `Syncable` shape (beam-tenancy's
     * `TenantUser`) names its central model and shares its global id, so the central user is that model's row with
     * that id. The host's central user model must query the central connection (both hosts pin
     * `$connection = 'central'`). A seat whose central row is missing resolves to {@see NO_CENTRAL_PRINCIPAL}. Any
     * other principal (a central user, a scope, a guest) is already its own central principal.
     */
    private function centralPrincipal(mixed $principal): mixed
    {
        if (! is_object($principal)
            || ! method_exists($principal, 'getCentralModelName')
            || ! method_exists($principal, 'getGlobalIdentifierKey')) {
            return $principal;
        }

        $class = $principal->getCentralModelName();
        if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
            return self::NO_CENTRAL_PRINCIPAL;
        }

        $central = new $class;
        $keyName = method_exists($central, 'getGlobalIdentifierKeyName')
            ? $central->getGlobalIdentifierKeyName()
            : $central->getKeyName();

        return $class::query()->where($keyName, $principal->getGlobalIdentifierKey())->first() ?? self::NO_CENTRAL_PRINCIPAL;
    }

    /** A humanized fallback title when the gate config declares none (RealmDefinition carries no title). */
    private function titleFor(string $key): string
    {
        return ucfirst(str_replace(['-', '_'], ' ', $key));
    }
}
