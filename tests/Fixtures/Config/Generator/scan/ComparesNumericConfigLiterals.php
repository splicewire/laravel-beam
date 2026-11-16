<?php

namespace Splicewire\Beam\Tests\Fixtures\Config\Generator\scan;

/**
 * Fixture source for typed enum inference (review-r1, 2026-11-16: ints and floats were ignored). Scanned as text, never
 * called. `retries` is an int compared with ints (and one string, which an int key must drop); `ratio` is a float
 * compared with a float and an int; `mode` is an `int|string` union (a bare env()) and gets no enum.
 */
class ComparesNumericConfigLiterals
{
    public function checks(): bool
    {
        return config('cs06.numeric.retries') === 3
            || in_array(config('cs06.numeric.retries'), [5, -1, 'many'], true)
            || config('cs06.numeric.ratio') === 0.5
            || config('cs06.numeric.ratio') === 1
            || config('cs06.numeric.mode') === 2
            || config('cs06.numeric.enabled') === true;
    }
}
