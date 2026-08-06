<?php

namespace Splicewire\Beam\Realm;

use Splicewire\Beam\Entitlements\EntitlementGate;

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
 * An UNGATED realm (no `beam.core.realm_gates` entry) always projects, unlocked. The gating rides beam
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
 */
class RealmManifestProjector
{
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
        $gates = (array) config('beam.core.realm_gates', config('beam.realm_gates', []));
        $manifest = [];

        foreach ($this->realms->all() as $key => $realm) {
            $gate = $gates[$key] ?? null;

            $entitled = $gate === null
                || $this->entitlements->allows((string) ($gate['entitlement'] ?? ''), $principal);

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
            $manifest[] = $this->applyOverlays($key, $descriptor, $principal);
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

    /** A humanized fallback title when the gate config declares none (RealmDefinition carries no title). */
    private function titleFor(string $key): string
    {
        return ucfirst(str_replace(['-', '_'], ' ', $key));
    }
}
