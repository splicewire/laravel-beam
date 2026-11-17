<?php

// Fixture: the host's own copy of one `tpanels` key (config-sections 07, the tenant row's redaction, root 29b67e82): a
// host-owned, host-ceilinged key bound to an env var, so the operator row names a host file and an env var a tenant
// row must never carry.
return [
    'driver' => env('BEAM_FIXTURE_TPANELS_DRIVER', 'array'),
];
