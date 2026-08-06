<?php

namespace Splicewire\Beam\Manifest;

/**
 * The RESOLUTION arity of a registry/manifest — how many of its registered entries a lookup engages
 * (canon: `the-seam-is-a-registry`). This is the axis the estate's "Registry" vs "Manifest" class-name
 * split was really groping toward: both are the SAME primitive (a registry at a seam), and arity — not the
 * suffix — is what actually differs. Declaring it here makes that legible instead of buried in the read
 * method's shape.
 *
 * Orthogonal to {@see ManifestSeam}: seam = HOW an entry gets IN (the injection socket); arity = how many
 * entries a resolution engages when read OUT. A pick-one config-source and a run-all singleton-accumulator
 * are different on both axes; naming both is the whole point of the index of indexes.
 */
enum ManifestArity: string
{
    /** Resolve ONE entry — most-specific/keyed wins, a miss falls back to a generic default. */
    case PickOne = 'pick-one';

    /** Compose MANY entries — an ordered chain each entry contributes to (fails loud, not silently skipped). */
    case ComposeMany = 'compose-many';

    /** Run ALL entries — a conjunction/enumeration; every registered entry is engaged. */
    case RunAll = 'run-all';

    /** A one-line account of what a read of this arity does. */
    public function blurb(): string
    {
        return match ($this) {
            self::PickOne => 'Read resolves ONE entry by key/selector; most-specific wins, miss falls back to a default.',
            self::ComposeMany => 'Read composes an ORDERED chain — each entry transforms in turn; a broken link fails loud.',
            self::RunAll => 'Read engages EVERY entry — the whole enumerated list, order-significant.',
        };
    }
}
