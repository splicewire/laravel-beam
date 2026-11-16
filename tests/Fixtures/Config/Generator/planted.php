<?php

// Fixture (config-sections ticket 06): a package file whose `mode` default is a string while the merged config holds a
// record there (a host replaced it), so the section inferred from the merged array cannot describe the file's default:
// the generator's own check must report it and exit 1.
return [
    'enabled' => (bool) env('BEAM_FIXTURE_PLANTED_ENABLED', true),
    'mode' => 'fast',
];
