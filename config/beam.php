<?php

return [

    /*
    |--------------------------------------------------------------------------
    | beam substrate
    |--------------------------------------------------------------------------
    | The app-substrate rung. This config is intentionally near-empty at mint:
    | beam boots headless and the leaf-extraction tickets (07-10) populate it as
    | the generic schema-record / media traits and host-hook registries land.
    |
    | beam depends on nothing above it — frame (the editor rung) depends on beam,
    | never the reverse (ADR-0082). Keep host/editor concerns out of this file.
    */

    // 'schema_record' => [ ... ]   // (ticket 07)
    // 'media'         => [ ... ]   // (ticket 08)
    // 'hooks'         => [ ... ]   // (webhook / sitemap / doctor registries)

];
