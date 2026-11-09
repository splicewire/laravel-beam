<?php

// Fixture: a package's own config file for the `widgets` root (ConfigSection tests).
return [
    'enabled' => env('BEAM_FIXTURE_WIDGETS_ENABLED', true),
    'limits' => [
        'per_page' => 20,
        'max' => 100,
    ],
    'driver' => 'array',
    'api_key' => env('BEAM_FIXTURE_WIDGETS_API_KEY'),
];
