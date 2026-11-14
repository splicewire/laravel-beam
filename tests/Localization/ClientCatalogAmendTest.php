<?php

use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Doctor\LocalizationCoverageAudit;
use Splicewire\Beam\Localization\Catalog\KeyCatalog;
use Splicewire\Beam\Localization\Client\Data\ClientCatalogAddressData;
use Splicewire\Beam\Localization\Client\Data\ClientCatalogData;
use Splicewire\Beam\Localization\Client\Data\ClientLocalizationData;
use Splicewire\Beam\Localization\Client\LangFingerprint;
use Splicewire\Beam\Localization\TextNamespaces;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 16 amend, beyond review-r1's probes (ReviewB11Test):
 * - B6: lang:keys and the coverage audit report the client population's actual state, additively.
 * - B7: an empty catalog stays a map at the bootstrap boundary.
 * - B4: the allocator's own decision, asked without side effects.
 */

beforeEach(function (): void {
    $this->lang = sys_get_temp_dir().'/beam-client-amend-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($this->lang.'/es', 0777, true);
    $this->app->useLangPath($this->lang);
    $this->app->forgetInstance('translation.loader');
    $this->app->forgetInstance('translator');
    config(['beam.localization.supported' => ['en', 'es']]);
    $this->app->forgetScopedInstances();
    @unlink(LangFingerprint::artifactPath());
});

it('reports a missing client catalog in lang:keys --json and marks the population partial', function (): void {
    config(['beam.localization.client_catalog' => $this->lang.'/absent.json']);
    Artisan::call('beam:lang:keys', ['--json' => true]);
    $output = json_decode(Artisan::output(), true);

    expect($output)->toHaveKeys(['population', 'entries', 'clientDiscovery'])
        ->and($output['clientDiscovery']['state'])->toBe('missing')
        ->and($output['clientDiscovery']['path'])->toBe($this->lang.'/absent.json')
        ->and($output['clientDiscovery']['partial'])->toBeTrue();
});

it('reports a valid, fully admitted client catalog as complete', function (): void {
    config(['beam.localization.client_catalog' => dirname(__DIR__).'/Fixtures/Localization/discovered-catalog.json']);
    $discovery = app(KeyCatalog::class)->clientDiscovery();

    expect($discovery['state'])->toBe('valid')
        ->and($discovery['refused'])->toBe([])
        ->and($discovery['partial'])->toBeFalse()
        ->and(app(KeyCatalog::class)->clientDiscoveryLine())->toBeNull();
});

it('never lets the coverage audit pass over a partial client population', function (): void {
    config(['beam.localization.client_catalog' => $this->lang.'/absent.json']);
    $findings = app(LocalizationCoverageAudit::class)->run();

    expect(collect($findings)->pluck('status')->unique()->values()->all())->not->toContain('pass')
        ->and(collect($findings)->pluck('detail')->implode("\n"))->toContain('The client population is PARTIAL')
        ->and(collect($findings)->pluck('detail')->implode("\n"))->toContain('absent.json');
});

it('keeps an empty catalog a map at the bootstrap boundary, as JSON and nested', function (): void {
    $empty = new ClientCatalogData(version: 1, locale: 'es', realm: null, direction: 'ltr', messages: []);
    $bootstrap = new ClientLocalizationData(
        address: new ClientCatalogAddressData(url: '/beam/localization/catalog/es?h='.$empty->hash(), hash: $empty->hash(), version: 1, locale: 'es', realm: null),
        initial: $empty,
    );

    expect(json_encode($empty))->toContain('"messages":{}')
        ->and(json_encode($bootstrap->toArray()))->toContain('"messages":{}')
        ->and(json_decode(json_encode($bootstrap))->initial->messages)->toBeInstanceOf(stdClass::class);
});

it('asks the allocator decision without hinting: a foreign hint is a refusal, an absent one is not', function (): void {
    $namespaces = app(TextNamespaces::class);
    $package = collect($namespaces->familyPackages())->keys()->first();
    $namespace = $namespaces->namespaceOf($package);
    $hintsBefore = app('translator')->getLoader()->namespaces();

    if (! isset($hintsBefore[$namespace])) {
        expect($namespaces->refusalFor($package))->toBeNull()
            ->and(app('translator')->getLoader()->namespaces())->toBe($hintsBefore);
    }
    app('translator')->addNamespace($namespace, '/unrelated/package/lang');

    expect($namespaces->refusalFor($package))->toContain('/unrelated/package/lang')
        ->and($namespaces->refusalFor('nobody/nothing'))->toContain('not an installed package');
});
