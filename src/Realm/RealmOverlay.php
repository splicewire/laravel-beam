<?php

namespace Splicewire\Beam\Realm;

use Schemastud\DataSchemas\Overlay\OverlayDocument;
use Schemastud\DataSchemas\Overlay\OverlayStack;

/**
 * Frame OS ticket 14 (ADR-0014 §A2): an ADDITIVE overlay on an EXISTING realm.
 *
 * An overlay ENRICHES a realm's projected descriptor — it adds fields, capabilities, optional launch
 * entries or overrides — and is resolved PHP-side, folded into the realm descriptor by the
 * {@see RealmManifestProjector} BEFORE the manifest emits. It NEVER stands up a new realm or a
 * standalone launchable tile: an overlay is keyed to a realm that must ALREADY be registered in the
 * {@see RealmRegistry}, and the projector applies it only to that realm's existing descriptor (an
 * overlay whose target realm is absent is a no-op — it cannot conjure a realm into being).
 *
 * beam knows only "overlays" here, keyed by realm key — NOT "satellites". The satellite tier
 * (`splicewire/laravel-satellite`) is one CALLER that registers an overlay; the frame stays
 * satellite-agnostic because by the time it reads the manifest it sees only realms, some carrying
 * overlay-added shape, indistinguishable in kind from unenriched ones.
 *
 * Enrichment is expressed as a `schemastud/laravel-data-schemas` overlay document (the reused overlay
 * PRIMITIVE — beam invents no parallel merge engine): a `{overlay, actions:[{target, override|merge|
 * unset}]}` fold applied to the realm descriptor array. In addition, the overlay may declare
 * ENTITLEMENT-GATED capabilities: each is projected present-but-LOCKED (with an upsell) for a principal
 * that does not hold its entitlement — the same soft-lock shape the projector already emits for a
 * soft-gated realm (ADR-0013). An entitled principal sees the capability enriched; an unentitled one
 * sees it locked, never absent.
 */
class RealmOverlay
{
    /**
     * @param  string  $realmKey  the EXISTING realm this overlay enriches (must be registered already).
     * @param  OverlayDocument  $document  the data-schema overlay folded into the realm descriptor.
     * @param  list<RealmOverlayCapability>  $capabilities  entitlement-gated capabilities the overlay adds;
     *                                                      each soft-locks (present + upsell) when unheld.
     * @param  string|null  $satelliteKey  optional provenance tag for the REGISTRAR's own bookkeeping
     *                                     (diagnostics/doctor). Deliberately NOT projected into the
     *                                     manifest — the frame must learn no satellite identity.
     */
    public function __construct(
        public readonly string $realmKey,
        public readonly OverlayDocument $document,
        public readonly array $capabilities = [],
        public readonly ?string $satelliteKey = null,
    ) {}

    /**
     * Build an overlay from a raw data-schema overlay array (`{overlay, actions}`) plus capability
     * descriptors — the ergonomic entry a registrar (e.g. the satellite tier) uses.
     *
     * @param  array<string, mixed>  $overlay  a raw `{overlay, actions:[...]}` data-schema overlay document.
     * @param  list<array<string, mixed>>  $capabilities  each `{key, entitlement?, upsell?}`.
     */
    public static function make(
        string $realmKey,
        array $overlay,
        array $capabilities = [],
        ?string $satelliteKey = null,
    ): self {
        return new self(
            realmKey: $realmKey,
            document: OverlayDocument::fromArray($overlay),
            capabilities: array_map(
                static fn (array $cap): RealmOverlayCapability => RealmOverlayCapability::fromArray($cap),
                array_values($capabilities),
            ),
            satelliteKey: $satelliteKey,
        );
    }

    /**
     * Fold this overlay's data-schema actions onto a realm descriptor array and return the enriched
     * array. Pure — the same descriptor + overlay always yield the same result. Capability soft-locking
     * is resolved separately (it needs the principal), by the projector.
     *
     * @param  array<string, mixed>  $descriptor
     * @return array<string, mixed>
     */
    public function enrich(array $descriptor): array
    {
        return (new OverlayStack([$this->document]))->apply($descriptor);
    }
}
