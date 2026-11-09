<?php

// Fixture: the host's copy of `widgets`. It declares `limits` at the top level, so mergeConfigFrom's shallow merge
// hides the package's `limits.max`; `driver` is env-fed here, in the host's file.
return [
    'limits' => [
        'per_page' => 50,
    ],
    'driver' => env('BEAM_FIXTURE_WIDGETS_DRIVER', 'redis'),
];
