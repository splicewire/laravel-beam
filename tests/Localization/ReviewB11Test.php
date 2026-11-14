<?php

use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Localization\Client\ClientCatalogResolver;
use Splicewire\Beam\Localization\Client\ClientNamespaces;
use Splicewire\Beam\Localization\Client\DiscoveredCatalog;
use Splicewire\Beam\Localization\Client\Jcs;
use Splicewire\Beam\Localization\Client\LangFingerprint;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);
beforeEach(function () {
    $this->lang = sys_get_temp_dir().'/review-b11-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($this->lang.'/vendor/schemastud-ui/es/operator', 0777, true);
    file_put_contents($this->lang.'/vendor/schemastud-ui/es/messages.php', "<?php return ['save'=>'Guardar'];");
    file_put_contents($this->lang.'/vendor/schemastud-ui/es/operator/messages.php', "<?php return ['save'=>'Operator wording'];");
    app()->useLangPath($this->lang);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');
    app()->forgetScopedInstances();
    config(['beam.localization.client_catalog' => __DIR__.'/../Fixtures/Localization/discovered-catalog.json', 'beam.localization.supported' => ['en', 'es'], 'app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    @unlink(LangFingerprint::artifactPath());
});
it('review refuses an anonymous fetch of the guarded operator realm', function () {
    expect(auth()->guest())->toBeTrue();
    $this->getJson('/beam/localization/catalog/es/operator')->assertForbidden();
});
it('review reloads changed translation contents without a test resetting translator', function () {
    $resolver = app(ClientCatalogResolver::class);
    $before = $resolver->resolve('es', null);
    $fp = LangFingerprint::translations();
    file_put_contents($this->lang.'/vendor/schemastud-ui/es/messages.php', "<?php return ['save'=>'Salvar'];");
    expect(LangFingerprint::translations())->not->toBe($fp);
    expect($resolver->resolve('es', null)->messages['schemastud-ui::messages.save']->text)->toBe('Salvar');
});
// B11 bounded observation (not a defect): discovery is read once per request scope, and a new scope sees new bytes.
it('review sees changed catalog bytes in the next request scope of the same process', function () {
    $path = $this->lang.'/catalog.json';
    copy(config('beam.localization.client_catalog'), $path);
    config(['beam.localization.client_catalog' => $path]);
    $resolver = app(ClientCatalogResolver::class);
    expect($resolver->resolve('en', null)->messages['schemastud-ui::messages.save']->text)->toBe('Save');
    $d = json_decode(file_get_contents($path));
    $d->payload->messages->{'schemastud-ui::messages.save'}->default = 'Store';
    $d->digest = 'sha256:'.Jcs::hash($d->payload);
    file_put_contents($path, Jcs::canonicalize($d));
    expect($resolver->resolve('en', null)->messages['schemastud-ui::messages.save']->text)->toBe('Save');
    app()->forgetScopedInstances();
    expect(app(ClientCatalogResolver::class)->resolve('en', null)->messages['schemastud-ui::messages.save']->text)->toBe('Store');
});
it('review refuses a sharesWith sibling whose hint belongs to an unrelated package', function () {
    app('translator')->addNamespace('frame', '/unrelated/package/lang');
    $catalog = DiscoveredCatalog::read(config('beam.localization.client_catalog'));
    expect(app(ClientNamespaces::class)->admit($catalog))->toHaveKey('frame');
});
it('review invalidates a memo when a namespace becomes refused', function () {
    $resolver = app(ClientCatalogResolver::class);
    expect($resolver->resolve('es', null)->messages)->toHaveKey('schemastud-ui::messages.save');
    app('translator')->addNamespace('schemastud-ui', '/unrelated/nonexistent/lang');
    app()->forgetScopedInstances();
    $resolver = app(ClientCatalogResolver::class);
    expect($resolver->refused())->toHaveKey('schemastud-ui');
    expect($resolver->resolve('es', null)->messages)->not->toHaveKey('schemastud-ui::messages.save');
});
it('review reports actual missing state in export rather than only a static population promise', function () {
    config(['beam.localization.client_catalog' => $this->lang.'/missing.json']);
    Artisan::call('beam:lang:missing', ['locale' => 'es', '--json' => true]);
    $output = json_decode(Artisan::output(), true);
    unset($output['population']);
    expect(json_encode($output))->toContain('missing.json');
});
it('review serves an empty messages map as an object', function () {
    $d = (object) ['payload' => (object) ['version' => 1, 'packages' => [], 'messages' => (object) []]];
    $d->digest = 'sha256:'.Jcs::hash($d->payload);
    $path = $this->lang.'/empty.json';
    file_put_contents($path, Jcs::canonicalize($d));
    config(['beam.localization.client_catalog' => $path]);
    $response = $this->getJson('/beam/localization/catalog/es')->assertOk();
    expect(json_decode($response->getContent())->messages)->toBeInstanceOf(stdClass::class);
});

it('review fingerprints every base path the FileLoader can read', function () {
    $extra = $this->lang.'-extra';
    mkdir($extra.'/es', 0777, true);
    file_put_contents($extra.'/es/messages.php', "<?php return ['profile_updated'=>'Perfil uno'];");
    app('translator')->getLoader()->addPath($extra);
    $resolver = app(ClientCatalogResolver::class);
    expect($resolver->resolve('es', null)->messages['messages.profile_updated']->text)->toBe('Perfil uno');
    $fp = LangFingerprint::translations();
    file_put_contents($extra.'/es/messages.php',"<?php return ['profile_updated'=>'Perfil dos'];");
    expect(LangFingerprint::translations())->not->toBe($fp);
});
