<?php

use Splicewire\Beam\Localization\Client\ClientCatalogResolver;
use Splicewire\Beam\Localization\Client\LangFingerprint;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);
it('narrow B2 B3 confirmation reloads a changed base-path precedence', function () {
    $root = sys_get_temp_dir().'/confirm-b11-order-'.getmypid().'-'.bin2hex(random_bytes(4));
    $a = $root.'/a';
    $b = $root.'/b';
    mkdir($a.'/es', 0777, true);
    mkdir($b.'/es', 0777, true);
    file_put_contents($a.'/es/messages.php', "<?php return ['profile_updated'=>'From A'];");
    file_put_contents($b.'/es/messages.php', "<?php return ['profile_updated'=>'From B'];");
    app()->useLangPath($a);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');
    app()->forgetScopedInstances();
    config(['beam.localization.client_catalog' => __DIR__.'/../Fixtures/Localization/discovered-catalog.json']);
    @unlink(LangFingerprint::artifactPath());
    $translator = app('translator');
    $loader = $translator->getLoader();
    $loader->addPath($b);
    $resolver = app(ClientCatalogResolver::class);
    $first = $resolver->resolve('es', null);
    expect($first->messages['messages.profile_updated']->text)->toBe('From B');
    $fp = LangFingerprint::translations();
    $priorPaths = $loader->paths();
    $loader->addPath($a);
    // Real FileLoader now answers A, and the fingerprint follows the ordered, non-de-duplicated source list (the fix).
    expect($loader->load('es', 'messages')['profile_updated'])->toBe('From A');
    expect(LangFingerprint::translations())->not->toBe($fp);
    app()->forgetScopedInstances();
    $next = app(ClientCatalogResolver::class)->resolve('es', null);
    expect($next->messages['messages.profile_updated']->text)->toBe('From A');
});
