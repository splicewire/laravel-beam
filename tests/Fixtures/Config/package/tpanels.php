<?php

// Fixture: a package's own config file for the `tpanels` root (config-sections ticket 07, the tenant tier).
return [
    'review' => false,
    'theme' => 'light',
    'limit' => 10,
    'driver' => 'array',
    'group' => [
        'review' => false, 'label' => 'base', 'deploy' => 'host-driver',
        'settings' => ['label' => 'central', 'fixed' => 'operator fixed', 'tags' => ['x' => 'X']],
    ],
    'routes' => ['a' => 'A', 'b' => 'B'],
    'extras' => ['k' => 'K', 'm' => 'M'],
];
