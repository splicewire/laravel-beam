<?php

namespace Splicewire\Beam\Localization;

/**
 * What the ladder answered for a key: the line (a string, or an array for a key that names a group of lines), the
 * locale whose rung supplied it (`sourceLocale`: a plural is selected by ITS rules, ruling 02 §2), the realm rung that
 * answered (null for the base), and where it came from (`file`, or `config` for a realm's configured label, ruling 05).
 */
final readonly class ResolvedLine
{
    /**
     * @param  string|array<array-key, mixed>  $line
     */
    public function __construct(
        public string|array $line,
        public string $sourceLocale,
        public ?string $realm,
        public string $origin,
    ) {}
}
