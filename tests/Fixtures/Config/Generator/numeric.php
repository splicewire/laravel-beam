<?php

// Fixture (review-r1's numeric-enum repro, 2026-11-16): an int, a float, a bare-env union and a bool, compared against
// literals in scan/ComparesNumericConfigLiterals.php.
return [
    'retries' => 3,
    'ratio' => 0.25,
    'mode' => env('BEAM_FIXTURE_NUMERIC_MODE', 1),
    'enabled' => false,
];
