<?php

// Fixture: a parent file whose `retrieval` key is its own root (`fleet.retrieval`), like `config/splicewire.php`.
return [
    'retrieval' => [
        'weight' => env('BEAM_FIXTURE_RETRIEVAL_WEIGHT', 0.5),
        'mode' => 'hybrid',
    ],
];
