<?php

namespace Splicewire\Beam\Localization\Presentation;

/**
 * A persisted or broadcast line as its reader sees it, and where it came from (localization 37; RULING-04 §5).
 *
 * - `reference`: rendered from a stored MessageReferenceData (a new write, R1).
 * - `identity`: rendered from the row's KNOWN identity (a registered presenter).
 * - `legacy`: the stored text, returned byte-identical. It is history the reader could not localize, so it is reported
 *   and never counted as translated.
 * - `none`: nothing to show.
 */
final readonly class PresentedLine
{
    public const REFERENCE = 'reference';

    public const IDENTITY = 'identity';

    public const LEGACY = 'legacy';

    public const NONE = 'none';

    public function __construct(
        public ?string $text,
        public string $source,
    ) {}

    /** Whether the reader's language produced this text (a stored reference or a known identity), not legacy history. */
    public function translated(): bool
    {
        return $this->source === self::REFERENCE || $this->source === self::IDENTITY;
    }
}
