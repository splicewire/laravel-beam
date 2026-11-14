<?php

use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Localization\Catalog\KeyCatalog;
use Splicewire\Beam\Localization\Client\ClientCatalogResolver;
use Splicewire\Beam\Localization\Client\ClientNamespaces;
use Splicewire\Beam\Localization\Client\DiscoveryState;
use Splicewire\Beam\Localization\Client\LangFingerprint;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 16: the client catalog reaches a host (ruling 02 §2–§4).
 * - The discovered catalog (written by schemastud's own JCS) is admitted under ruling 01 §3.
 * - Every key walks the ladder once, and each line carries the locale that ANSWERED.
 * - The catalog is served by hash: private, with ETag/304.
 * - The memo keys on content fingerprints, so a changed line invalidates it with no artifact and no optimize:clear
 *   (coord-lead ruling (a)).
 * - The KeyCatalog's CLIENT population lists JS-only keys with equal standing.
 */

beforeEach(function (): void {
    $this->lang = sys_get_temp_dir().'/beam-client-lang-'.getmypid().'-'.bin2hex(random_bytes(4));
    foreach (['vendor/schemastud-ui/es', 'vendor/schemastud-ui/es/operator', 'vendor/schemastud-ui/es-MX'] as $dir) {
        mkdir($this->lang.'/'.$dir, 0777, true);
    }
    file_put_contents($this->lang.'/vendor/schemastud-ui/es/messages.php', "<?php return ['save' => 'Guardar', 'items' => 'un elemento|:count elementos'];");
    file_put_contents($this->lang.'/vendor/schemastud-ui/es/operator/messages.php', "<?php return ['save' => 'Guardar (operador)'];");
    $this->app->useLangPath($this->lang);
    $this->app->forgetInstance('translation.loader');
    $this->app->forgetInstance('translator');
    config([
        'beam.localization.client_catalog' => dirname(__DIR__).'/Fixtures/Localization/discovered-catalog.json',
        'beam.localization.supported' => ['en', 'es', 'es-MX', 'ja'],
        'app.locale' => 'en',
    ]);
    $this->app->forgetScopedInstances();
    @unlink(LangFingerprint::artifactPath());
});

function clientResolver(): ClientCatalogResolver
{
    app()->forgetScopedInstances();

    return app(ClientCatalogResolver::class);
}

it('admits a JS-only namespace with an empty hint and a declared sharesWith sibling', function (): void {
    expect(clientResolver()->refused())->toBe([])
        ->and(app('translator')->getLoader()->namespaces()['schemastud-ui'] ?? null)->toBe(ClientNamespaces::emptyHint('schemastud-ui'));
});

it('resolves each line with the locale that answered, the English default on a miss', function (): void {
    $es = clientResolver()->resolve('es', null)->messages;
    $ja = clientResolver()->resolve('ja', null)->messages;
    $esMx = clientResolver()->resolve('es-MX', null)->messages;

    expect([$es['schemastud-ui::messages.save']->text, $es['schemastud-ui::messages.save']->sourceLocale])->toBe(['Guardar', 'es'])
        // A host override reaches the JS-only namespace; a JS-only key needs no PHP sibling.
        ->and([$ja['schemastud-ui::messages.save']->text, $ja['schemastud-ui::messages.save']->sourceLocale])->toBe(['Save', 'en'])
        // Ruling 02's plural fixture: es-MX misses, es answers, so the client selects with Spanish.
        ->and([$esMx['schemastud-ui::messages.items']->text, $esMx['schemastud-ui::messages.items']->sourceLocale])->toBe(['un elemento|:count elementos', 'es'])
        ->and($es['messages.profile_updated']->text)->toBe('Profile updated.');
});

it('reads a realm rung for one realm only', function (): void {
    expect(clientResolver()->resolve('es', 'operator')->messages['schemastud-ui::messages.save']->text)->toBe('Guardar (operador)')
        ->and(clientResolver()->resolve('es', 'tenant')->messages['schemastud-ui::messages.save']->text)->toBe('Guardar');
});

it('keeps a hash while nothing changes, and a changed line changes it and invalidates the memo with no artifact', function (): void {
    $before = clientResolver()->resolve('es', null)->hash();
    $fingerprint = LangFingerprint::translations();
    expect(clientResolver()->resolve('es', null)->hash())->toBe($before);

    file_put_contents($this->lang.'/vendor/schemastud-ui/es/messages.php', "<?php return ['save' => 'Salvar', 'items' => 'un elemento|:count elementos'];");
    app()->forgetInstance('translator');

    expect(LangFingerprint::translations())->not->toBe($fingerprint)
        ->and(clientResolver()->resolve('es', null)->messages['schemastud-ui::messages.save']->text)->toBe('Salvar')
        ->and(clientResolver()->resolve('es', null)->hash())->not->toBe($before);
});

it('holds the artifact fingerprint until beam:lang:fingerprint runs again (explicit invalidation)', function (): void {
    Artisan::call('beam:lang:fingerprint');
    $stored = LangFingerprint::translations();
    file_put_contents($this->lang.'/vendor/schemastud-ui/es/messages.php', "<?php return ['save' => 'Otro'];");

    expect(LangFingerprint::translations())->toBe($stored);
    Artisan::call('beam:lang:fingerprint');
    expect(LangFingerprint::translations())->not->toBe($stored);
    Artisan::call('beam:lang:fingerprint', ['--clear' => true]);
    expect(is_file(LangFingerprint::artifactPath()))->toBeFalse();
});

it('serves the catalog by hash: private, ETag, 304, and the existing 404 for an unknown locale or realm', function (): void {
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]); // the default `web` middleware encrypts cookies
    $catalog = clientResolver()->resolve('es', null);
    $response = $this->getJson('/beam/localization/catalog/es')->assertOk();

    expect($response->json('version'))->toBe(1)
        ->and($response->json('messages')['schemastud-ui::messages.save'])->toBe(['text' => 'Guardar', 'sourceLocale' => 'es'])
        ->and($response->headers->get('ETag'))->toBe('"'.$catalog->hash().'"')
        ->and($response->headers->get('Cache-Control'))->toContain('private');

    $this->getJson('/beam/localization/catalog/es', ['If-None-Match' => '"'.$catalog->hash().'"'])->assertStatus(304);
    $this->getJson('/beam/localization/catalog/fr')->assertNotFound()->assertJsonPath('code', 'client_catalog_locale_unsupported');
    $this->getJson('/beam/localization/catalog/es/no-such-realm')->assertNotFound()->assertJsonPath('code', 'client_catalog_realm_unknown');
});

it('addresses a catalog by its content hash, and shares no initial catalog without a valid discovery', function (): void {
    $address = clientResolver()->address('es', null);
    expect($address->url)->toBe('/beam/localization/catalog/es?h='.$address->hash)->and($address->version)->toBe(1);

    config(['beam.localization.client_catalog' => '/nonexistent/messages.json']);
    $bootstrap = clientResolver()->bootstrap(request());
    expect($bootstrap->initial)->toBeNull()
        ->and(app(KeyCatalog::class)->clientCatalog()?->state)->toBe(DiscoveryState::Missing);
});

it('flags a realm override naming a client key the catalog lacks, and accepts a real one (ruling 02 worked path)', function (): void {
    file_put_contents($this->lang.'/vendor/schemastud-ui/es/operator/messages.php', "<?php return ['save' => 'Guardar (operador)', 'svae' => 'typo'];");
    app()->forgetInstance('translator');
    clientResolver();

    $flagged = json_encode(array_map(static fn ($f) => (array) $f, app(Splicewire\Beam\Doctor\LocalizationLangFileAudit::class)->run()));

    expect($flagged)->toContain('schemastud-ui::messages.svae')
        ->and($flagged)->not->toContain('schemastud-ui::messages.save"');
});

it('reports the discovered catalog through the doctor: valid with its count, missing, and a refused namespace', function (): void {
    $run = static fn (): array => app(Splicewire\Beam\Doctor\LocalizationClientCatalogAudit::class)->run();
    $text = static fn (array $findings): string => json_encode(array_map(static fn ($f) => (array) $f, $findings));

    expect($text($run()))->toContain('4 client key(s) from 2 package(s)');

    config(['beam.localization.client_catalog' => '/nonexistent/messages.json']);
    clientResolver();
    expect($text($run()))->toContain('missing')->toContain('never counts as coverage');

    config(['beam.localization.client_catalog' => dirname(__DIR__).'/Fixtures/Localization/discovered-catalog.json']);
    app('translator')->addNamespace('schemastud-ui', '/somewhere/else');
    clientResolver();
    expect($text($run()))->toContain('Client namespace [schemastud-ui] was refused')
        ->and(clientResolver()->defaults())->not->toHaveKey('schemastud-ui::messages.save');
});

it('lists client keys with their English in the key catalog and the missing-key export', function (): void {
    $entry = app(KeyCatalog::class)->byKey()['schemastud-ui::messages.save'] ?? null;
    expect($entry?->english)->toBe('Save')->and($entry?->source)->toBe('client')
        ->and(KeyCatalog::POPULATION)->toContain('CLIENT');

    Artisan::call('beam:lang:missing', ['locale' => 'ja', '--json' => true]);
    expect(Artisan::output())->toContain('schemastud-ui::messages.save');
});
