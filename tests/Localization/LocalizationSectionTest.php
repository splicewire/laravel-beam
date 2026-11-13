<?php

use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Splicewire\Beam\Localization\Config\LocalizationSection;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 06 — beam's localization config is a Config Section (ADR-0245): registered by beam with its package file,
 * every key Host-tier with a Deploy effect, and the shipped defaults valid against it.
 */

const LOCALIZATION_PACKAGE_FILE = __DIR__.'/../../config/beam/localization.php';

it('registers beam.localization with beam as its owner and its package file', function (): void {
    $section = app(ConfigSectionRegistry::class)->get('beam.localization');

    expect($section)->not->toBeNull()
        ->and($section->section)->toBe(LocalizationSection::class)
        ->and($section->owner)->toBe('splicewire/laravel-beam')
        ->and(realpath((string) $section->packageFile))->toBe(realpath(LOCALIZATION_PACKAGE_FILE));
});

it('declares every key Host-tier with a Deploy effect, and the config file names exactly those keys', function (): void {
    $schema = app(ConfigSectionSchema::class)->for(LocalizationSection::class);
    $keys = array_keys(require LOCALIZATION_PACKAGE_FILE);

    expect(array_keys($schema['properties']))->toEqualCanonicalizing($keys);
    foreach ($schema['properties'] as $key => $property) {
        expect($property[ConfigSectionSchema::KEYWORD]['ceiling'])->toBe('host', $key)
            ->and($property[ConfigSectionSchema::KEYWORD]['effect'])->toBe('deploy', $key);
    }
});

it('accepts the shipped defaults, and refuses a default of the wrong type', function (): void {
    $gate = app(AcceptanceGate::class);
    $schema = app(ConfigSectionSchema::class)->for(LocalizationSection::class);
    $defaults = require LOCALIZATION_PACKAGE_FILE;

    expect($gate->accepts($defaults, $schema))->toBeTrue()
        ->and($gate->accepts([...$defaults, 'http_profile' => 'yes'], $schema))->toBeFalse();
});

it('merges the package file into config', function (): void {
    expect(config('beam.localization.fallback'))->toBe('en')
        ->and(config('beam.localization.http_profile'))->toBeFalse();
});
