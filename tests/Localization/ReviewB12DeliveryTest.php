<?php

use Splicewire\Beam\Localization\LocaleResolver;
use Splicewire\Beam\Localization\LocaleScope;
use Splicewire\Beam\Localization\LocalizationContext;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);
it('review delivery without a declared realm enters the base rather than the senders realm', function () {
    config(['beam.localization.supported' => ['en', 'es'], 'app.locale' => 'en']);
    $observed = LocaleScope::runIn('es', 'operator', fn () => app(LocaleResolver::class)->deliver(null, fn () => app(LocalizationContext::class)->realm()));
    expect($observed)->toBe([true, null]);
});
