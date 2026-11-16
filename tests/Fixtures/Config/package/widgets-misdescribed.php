<?php

// Fixture (config-sections ticket 06): a package file for `widgets` whose `limits` default is not the record
// WidgetsSection declares, so the coverage audit must report that the section misdescribes its file.
return [
    'enabled' => true,
    'limits' => 'unlimited',
    'driver' => 'array',
];
