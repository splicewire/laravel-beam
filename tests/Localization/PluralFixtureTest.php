<?php

use Splicewire\Beam\Localization\PluralSelectorTable;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 07 — the installed framework's plural selector against the committed plural fixture (ruling 02 §2).
 * The fixture is the cross-runtime contract the client seam evaluates too; a framework whose selector rules drift from it
 * fails here, loudly, rather than shipping a client that picks a different form than the server. This test never
 * writes: `composer lang:plural-fixture -- --write` does, after a person has read the change.
 */

function committedPluralFixture(): array
{
    $bytes = (string) file_get_contents(__DIR__.'/../Fixtures/Localization/plural-selector.json');

    return ['bytes' => $bytes, 'table' => json_decode($bytes, true, flags: JSON_THROW_ON_ERROR)];
}

it('is committed as JCS: re-encoding it reproduces its bytes exactly', function (): void {
    ['bytes' => $bytes, 'table' => $table] = committedPluralFixture();

    expect(PluralSelectorTable::encode($table))->toBe($bytes)
        ->and(str_ends_with($bytes, "\n"))->toBeFalse();
});

it('matches the installed selector\'s rules: the same aliases and the same index for every count', function (): void {
    $committed = committedPluralFixture()['table'];
    $installed = PluralSelectorTable::fromInstalled();

    expect($installed['aliases'])->toBe($committed['aliases'], 'the installed selector names different locale aliases')
        ->and($installed['counts'])->toEqual($committed['counts']);

    foreach ($committed['indexes'] as $alias => $indexes) {
        expect($installed['indexes'][$alias] ?? null)->toBe($indexes, "plural rules drifted for [{$alias}]");
    }
});

it('normalizes a requested locale to the alias the selector is handed, as the fixture records', function (): void {
    $committed = committedPluralFixture()['table'];

    expect(PluralSelectorTable::fromInstalled()['normalization'])->toBe($committed['normalization']);
});

it('records where its rules came from', function (): void {
    $baseline = committedPluralFixture()['table']['baseline'];

    expect($baseline['framework'])->toStartWith('laravel/framework ')
        ->and($baseline['selector'])->toBe('src/Illuminate/Translation/MessageSelector.php')
        ->and($baseline['sha256'])->toMatch('/^[0-9a-f]{64}$/');
});
