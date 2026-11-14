<?php

use Splicewire\Beam\Localization\Client\InvalidClientCatalog;
use Splicewire\Beam\Localization\Client\Jcs;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 16: RFC 8785 (JCS) in PHP, at parity with schemastud's messages/bin/jcs.mjs. jcs.json is consumed BYTE FOR
 * BYTE from schemastud 6c22dbf4 (file sha256 92d15ea3…), and its committed digest (630e49af…) must be reproduced
 * (coord-lead 22:47Z). The admission cases are the JS test's own (messages/tests/jcs.test.mjs).
 */

function jcsFixture(): Splicewire\Beam\Localization\Client\JsonObject
{
    $path = dirname(__DIR__).'/Fixtures/Localization/jcs.json';
    expect(hash_file('sha256', $path))->toBe('92d15ea35e561499afc83cd821f1ceccef73387e8511c495ceae37f17b78b34c');

    return Jcs::parse((string) file_get_contents($path));
}

it('matches the committed cross-language JCS bytes, UTF-16 key order and numeric spelling', function (): void {
    $fixture = jcsFixture();
    $canonical = Jcs::canonicalize($fixture['input']);

    expect($canonical)->toBe($fixture['canonical'])
        ->and(hash('sha256', $canonical))->toBe('630e49afdc67fc154914adf3069a75d80dcf0c9abdbcb0c265b1e5c25858a3ce')
        ->and($fixture['sha256'])->toBe('630e49afdc67fc154914adf3069a75d80dcf0c9abdbcb0c265b1e5c25858a3ce');
});

it('refuses unsafe JSON admission', function (string $json): void {
    expect(fn () => Jcs::parse($json))->toThrow(InvalidClientCatalog::class);
})->with(['{"a":1,"\\u0061":2}', '{"x":9007199254740993}', '{"x":1e999}', '{"x":"\\ud800"}', '{"a":1,"a":2}', '[1,]', '{"a":1} x', "\"\x01\"", '01']);

it('preserves escaped values and admits a proto-like member as plain data', function (): void {
    $value = Jcs::parse(' { "__proto__": {"label":"a\\"b\\\\c\\n"}, "array": [true, false, null, 0.5] } ');

    expect(Jcs::canonicalize($value))->toBe('{"__proto__":{"label":"a\\"b\\\\c\\n"},"array":[true,false,null,0.5]}');
});

it('spells numbers as ES6 Number::toString does', function (float|int $number, string $spelled): void {
    expect(Jcs::canonicalize($number))->toBe($spelled);
})->with([[0.5, '0.5'], [100, '100'], [1e21, '1e+21'], [1e20, '100000000000000000000'], [0.000001, '0.000001'],
    [1e-7, '1e-7'], [-1.5, '-1.5'], [9007199254740991, '9007199254740991'], [0.0, '0'], [-0.0, '0'], [333333333.3333333, '333333333.3333333'],
    [1e30, '1e+30'], [1e-27, '1e-27'], [4.5, '4.5'], [0.002, '0.002']]);

it('distinguishes an empty object from an empty array, and never escapes "/" or U+2028', function (): void {
    expect(Jcs::canonicalize(Jcs::parse('{"o":{},"a":[]}')))->toBe('{"a":[],"o":{}}')
        ->and(Jcs::canonicalize("a/b\u{2028}"))->toBe("\"a/b\u{2028}\"");
});
