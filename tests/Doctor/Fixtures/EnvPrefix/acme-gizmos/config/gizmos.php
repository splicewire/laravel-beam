<?php

// env-inventory 02 fixture: `config/gizmos.php` owns `GIZMOS_`, and also reads acme/widgets' `WIDGETS_SHARED` with a
// different default.
return [
    'on' => env('GIZMOS_ON', false),
    'shared' => env('WIDGETS_SHARED', false),
];
