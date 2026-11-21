<?php

use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Splicewire\Beam\Localization\Client\ClientCatalogResolver;
use Splicewire\Beam\Localization\Client\Data\ClientCatalogData;
use Splicewire\Beam\Localization\Client\Data\ClientMessageLineData;
use Splicewire\Beam\Localization\Enums\TextDirection;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * ticket 19: `ClientCatalogData::$direction` is the backed enum `TextDirection`, replacing a `string` carrying
 * `'ltr'`/`'rtl'`. The compatibility rule binds: the wire value and the content hash do not change.
 */

it('keeps the catalog content hash byte-equal to the string it replaced', function (): void {
    $messages = ['app.greeting' => new ClientMessageLineData('Salām', 'fa')];
    $catalog = new ClientCatalogData(version: 1, locale: 'fa', realm: 'tenant', direction: TextDirection::Rtl, messages: $messages);

    // A hex literal taken at base f3778d4 (a `string $direction` carrying 'rtl') — NOT recomputed through Jcs here, so
    // this pins the bytes rather than tautologically re-deriving them.
    expect($catalog->hash())->toBe('a2d90cc58d178b56b1eb7383fe124fdce61ccd18d5e521cd38d6ddd42a917b01');
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

it('tightens the direction JSON schema to a reference to the enum definition', function (): void {
    // The accounted change (ticket 19, compatibility rule): laravel-data-schemas replaces the property's bare
    // `type: string` with a reference to an enum definition that lists exactly the two served values.
    $schema = (new JsonSchemaGenerator(['strategies' => config('data-schemas.strategies')]))
        ->generate(new ReflectionClass(ClientCatalogData::class));

    expect($schema['properties']['direction'])->toBe(['$ref' => '#/$defs/TextDirection'])
        ->and($schema['$defs']['TextDirection'])->toBe([
            'type' => 'string',
            'title' => 'TextDirection',
            'enum' => ['ltr', 'rtl'],
        ]);
});
