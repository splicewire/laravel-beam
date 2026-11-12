<?php

/*
 * The `lang:plural-fixture` command (`composer lang:plural-fixture`, then `-- --write`): generate the plural fixture
 * (tests/Fixtures/Localization/plural-selector.json) from the INSTALLED framework selector and print whether it differs
 * from the committed copy. Only --write writes. The verification half is tests/Localization/PluralFixtureTest.php, which
 * never writes. Regenerate only after reading what changed: a changed rule is a cross-runtime contract change that the
 * client message seam's copy of this table (localization ticket 15) must take too.
 */

use Splicewire\Beam\Localization\PluralSelectorTable;

require __DIR__.'/../vendor/autoload.php';

$file = __DIR__.'/Fixtures/Localization/plural-selector.json';
$fresh = PluralSelectorTable::encode(PluralSelectorTable::fromInstalled());
$committed = is_file($file) ? (string) file_get_contents($file) : null;

if ($committed === $fresh) {
    fwrite(STDOUT, 'plural fixture: unchanged (sha256 '.hash('sha256', $fresh).")\n");
    exit(0);
}

fwrite(STDOUT, 'plural fixture: '.($committed === null ? 'missing' : 'differs').' (fresh sha256 '.hash('sha256', $fresh).")\n");

if (! in_array('--write', $argv, true)) {
    fwrite(STDOUT, "run with --write to write it\n");
    exit(1);
}

@mkdir(dirname($file), 0777, true);
file_put_contents($file, $fresh);
fwrite(STDOUT, "wrote {$file}\n");
exit(0);
