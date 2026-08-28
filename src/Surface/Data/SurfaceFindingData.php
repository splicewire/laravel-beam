<?php

namespace Splicewire\Beam\Surface\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One corroboration finding: what the document says about a seam, what the runtime shows, and how much
 * weight the pairing can carry.
 *
 * ## `provenanceRank` is an integer on purpose
 *
 * The consuming compliance tier has a named trust ladder (Declared / Derived / Verified). Beam does not,
 * and must not: naming those tiers here would mean maintaining a second copy of a vocabulary that
 * already exists above, and reconciling the two in any report spanning both. So this carries a bare
 * **integer rank, higher is stronger** — the same move the knowledge kernel makes with its own
 * `provenanceFloor`, where the kernel "never names the tiers". Beam's two ranks describe only what beam
 * can see: a document said it ({@see RANK_DOCUMENTED}), or a live runtime was walked
 * ({@see RANK_OBSERVED}). Mapping those onto the compliance ladder is the consumer's job.
 */
#[TypeScript]
class SurfaceFindingData extends BeamData
{
    /** The finding rests on the document's own claim; nothing checked it against a running system. */
    public const RANK_DOCUMENTED = 1;

    /** A live router was walked and this finding reflects what it does. */
    public const RANK_OBSERVED = 3;

    /** A seam the document describes and the runtime confirms. */
    public const KIND_AGREEMENT = 'agreement';

    /** The document claims authentication; the router shows none. The finding that matters most. */
    public const KIND_DOCUMENTED_AUTHENTICATED_BUT_OPEN = 'documented_authenticated_but_open';

    /** The document declares the seam public; the router gates it. A doc defect, not a hole. */
    public const KIND_DOCUMENTED_PUBLIC_BUT_GATED = 'documented_public_but_gated';

    /** Present in the router, absent from the document. */
    public const KIND_UNDOCUMENTED_SURFACE = 'undocumented_surface';

    /** Present in the document, absent from the router. */
    public const KIND_DOCUMENTED_BUT_UNROUTED = 'documented_but_unrouted';

    /** The document never said whether the seam is secured. */
    public const KIND_UNDECLARED_SECURITY = 'undeclared_security';

    /** The runtime could not determine a facet, so nothing is claimed about it either way. */
    public const KIND_UNDETERMINED_FACET = 'undetermined_facet';

    /** A declared ability with no resolvable gate behind it. */
    public const KIND_UNRESOLVABLE_GATE = 'unresolvable_gate';

    /** A live surface that declares no data shape — read from the negative-space audit, not re-derived. */
    public const KIND_UNDECLARED_SHAPE = 'undeclared_shape';

    public function __construct(
        public string $kind,
        public string $signature,
        public ?string $facet = null,
        public ?string $documented = null,
        public ?string $observed = null,
        public int $provenanceRank = self::RANK_DOCUMENTED,
        public ?string $location = null,
    ) {}

    public function isCorroborated(): bool
    {
        return $this->provenanceRank >= self::RANK_OBSERVED;
    }
}
