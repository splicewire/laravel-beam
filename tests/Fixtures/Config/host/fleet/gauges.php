<?php

// Fixture: a host-only, map-shaped root (`fleet.gauges`) keyed by names with dots, like `app.meters`.
return [
    'openai.tokens' => [
        'label' => 'OpenAI tokens',
        'unit' => 'token',
    ],

    'google.cse.query' => [
        'label' => 'Google web search',
        'unit' => 'query',
        'unit_cost_usd' => (float) env('BEAM_FIXTURE_CSE_COST_USD', 0.005),
    ],
];
