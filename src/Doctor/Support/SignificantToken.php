<?php

namespace Splicewire\Beam\Doctor\Support;

/**
 * One non-whitespace, non-comment token, carrying the three facts {@see SchemaCreateScanner} walks on:
 * where it sits in the stream, what it says, and which line it is on.
 *
 * A value object rather than a tuple because `token_get_all()` returns two different shapes — an array
 * for a named token and a bare string for punctuation — and the scanner's whole job is to step across a
 * `Schema :: connection ( … ) -> create` chain built from both. Normalising once at the read is what
 * lets the chain-walking read as a chain.
 */
class SignificantToken
{
    public function __construct(
        /** Index in the `token_get_all()` stream — the resume point for the next hop. */
        public int $index,
        /** The token's text: a name, or the punctuation itself for `::`, `->`, `(`, `)`. */
        public string $text,
        /** 1-based source line, or 0 for punctuation, which `token_get_all()` reports without one. */
        public int $line,
    ) {}
}
