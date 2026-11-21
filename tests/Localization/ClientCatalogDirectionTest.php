<?php

use Splicewire\Beam\Localization\Client\ClientCatalogResolver;
use Splicewire\Beam\Localization\Client\Data\ClientCatalogData;
use Splicewire\Beam\Localization\Client\Data\ClientMessageLineData;
use Splicewire\Beam\Localization\Client\Jcs;
use Splicewire\Beam\Localization\Client\JsonObject;
use Splicewire\Beam\Localization\TextDirection;
use Splicewire\Beam\Tests\TestCase;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;

uses(TestCase::class);

/*
 * ticket 19: `ClientCatalogData::$direction` is the backed enum `TextDirection`, replacing a `string` carrying
 * `'ltr'`/`'rtl'`. The compatibility rule binds: the wire value and the content hash do not change. The enum feeds
 * both through its case value, so the bytes are identical to the string the base served.
 */

it('keeps the catalog content hash byte-equal to the string it replaced', function (): void {
    $messages = ['app.greeting' => new ClientMessageLineData('Salām', 'fa')];
    $catalog = new ClientCatalogData(version: 1, locale: 'fa', realm: 'tenant', direction: TextDirection::Rtl, messages: $messages);

    // The exact JCS the base (a `string $direction`) hashed, rebuilt from the literal the enum's case value equals.
    $stringEquivalentHash = Jcs::hash(new JsonObject([
        'version' => 1,
        'locale' => 'fa',
        'realm' => 'tenant',
        'direction' => 'rtl',
        'messages' => new JsonObject(['app.greeting' => new JsonObject(['text' => 'Salām', 'sourceLocale' => 'fa'])]),
    ]));

    expect($catalog->hash())->toBe($stringEquivalentHash);
});

it('serves the direction as the same bare string on the wire', function (): void {
    $catalog = new ClientCatalogData(version: 1, locale: 'fa', realm: null, direction: TextDirection::Rtl, messages: []);

    expect(json_decode(json_encode($catalog), true)['direction'])->toBe('rtl')
        ->and(json_encode($catalog->toArray()))->toContain('"direction":"rtl"');
});

it('resolves the writing direction to the enum, with the RTL set and its values unchanged', function (): void {
    expect(ClientCatalogResolver::direction('ar'))->toBe(TextDirection::Rtl)
        ->and(ClientCatalogResolver::direction('he-IL'))->toBe(TextDirection::Rtl)
        ->and(ClientCatalogResolver::direction('ckb'))->toBe(TextDirection::Rtl)
        ->and(ClientCatalogResolver::direction('en'))->toBe(TextDirection::Ltr)
        ->and(ClientCatalogResolver::direction('fr_FR'))->toBe(TextDirection::Ltr)
        ->and(TextDirection::Ltr->value)->toBe('ltr')
        ->and(TextDirection::Rtl->value)->toBe('rtl');
});

it('tightens the direction JSON schema from a bare string to the enum values', function (): void {
    // The accounted change (ticket 19, compatibility rule): laravel-data-schemas replaces the property's bare
    // `type: string` with the enum, listing its two values. The wire string is unchanged; the schema is narrowed.
    $schema = (new JsonSchemaGenerator(['strategies' => config('data-schemas.strategies')]))
        ->generate(new ReflectionClass(ClientCatalogData::class));
    $json = (string) json_encode($schema);

    expect($schema['properties']['direction'])->not->toBe(['type' => 'string'])
        ->and($json)->toContain('ltr')
        ->and($json)->toContain('rtl');
});
