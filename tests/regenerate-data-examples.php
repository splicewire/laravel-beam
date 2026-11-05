<?php

/*
 * The `docs:examples` command (`composer docs:examples`, then `-- --write`): capture each data example under
 * docs/registries/examples in a fresh application booted as this package's tests boot it, and print what would change.
 * Only --write writes. The verification half is tests/Registries/DataExamplesTest.php, which never writes.
 */

use Rushing\Popcorn\Registries\Docs\DataExamples;
use Splicewire\Beam\Tests\TestCase;

require __DIR__.'/../vendor/autoload.php';

exit(DataExamples::main($argv, dirname(__DIR__), fn () => (new class('data-examples') extends TestCase {})->createApplication()));
