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
 * Inert by default: an empty `realm_gates` gates nothing, so every realm projects unlocked and an existing
 * host is byte-for-byte.
 */
class RealmManifestProjector
{
    public function __construct(
        private RealmRegistry $realms,
        private EntitlementGate $entitlements,
    ) {}

    /**
     * Project the realm manifest for a principal.
     *
     * @return list<array{key: string, title: string, routeBase: string, locked: bool, upsell: array<string, mixed>|null}>
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

            $manifest[] = [
                'key' => $key,
                'title' => (string) ($gate['title'] ?? $this->titleFor($key)),
                'routeBase' => $realm->routeBase,
                'locked' => $locked,
                'upsell' => $locked ? ($gate['upsell'] ?? null) : null,
            ];
        }

        return $manifest;
    }

    /** A humanized fallback title when the gate config declares none (RealmDefinition carries no title). */
    private function titleFor(string $key): string
    {
        return ucfirst(str_replace(['-', '_'], ' ', $key));
    }
}
