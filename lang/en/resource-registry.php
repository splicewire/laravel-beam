<?php

/*
 * The developer zone's resource registry: its facet options (localization 21; coord-lead ruling 23:18Z on SIGNATURES-21
 * §2(b)). Keys are `beam::resource-registry.options.<facet>.<value>`, where `<value>` is the option's wire value.
 */
return [
    'options' => [
        'section' => [
            'none' => 'Unsectioned',
        ],
        'realm' => [
            'none' => 'No realm',
        ],
        'capability' => [
            'streams' => 'Lists (StreamsRecords)',
            'queries' => 'Queries (QueriesRecords)',
            'resolves' => 'Resolves one (ResolvesRecord)',
            'writes' => 'Writes (WritesRecords)',
            'vocabulary' => 'Declares facets',
        ],
        'posture' => [
            'model-backed' => 'Model-backed',
            'declared-ability' => 'Model-less, gated',
            'undeclared' => 'Model-less, undeclared',
        ],
        'nav' => [
            'seated' => 'In a nav',
            'unseated' => 'Not in any nav',
        ],
        'disagreement' => [
            'some' => 'Intent exceeds capability',
            'none' => 'Consistent',
        ],
    ],
];
