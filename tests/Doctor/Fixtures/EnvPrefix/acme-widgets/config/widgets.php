<?php

// env-inventory 02 fixture: `config/widgets.php` owns `WIDGETS_`. One conforming name, one shared with acme/gizmos,
// one grandfathered candidate (LEGACY_WIDGET_MODE) and one declared third-party name.
return [
    'enabled' => env('WIDGETS_ENABLED', true),
    'shared' => env('WIDGETS_SHARED', true),
    'mode' => env('LEGACY_WIDGET_MODE', 'a'),
    'stripe' => env('STRIPE_SECRET'),
];
