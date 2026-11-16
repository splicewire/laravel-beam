<?php

use Splicewire\Beam\Tests\Fixtures\Config\WidgetsSection;

// Fixture (config-sections ticket 06): a value that is a class-string of a registry's entry type, and a key a `match`
// compares against literals.
return [
    'section' => WidgetsSection::class,
    'mode' => env('BEAM_FIXTURE_REGISTRY_MODE', 'fast'),
];
