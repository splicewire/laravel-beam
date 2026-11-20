<?php

use Splicewire\Beam\Conformance\BeamManifest;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

function manifestFixture(string $body): BeamManifest
{
    return BeamManifest::parse("---\n{$body}\n---\n# Heading\n\nprose\n");
}

it('reads the declared fields from front matter', function () {
    $manifest = manifestFixture(<<<'YAML'
satellite: splicewire-marketing
variant: blade-vue
adopts: [content-pages, publishing]
YAML);

    expect($manifest->satellite())->toBe('splicewire-marketing')
        ->and($manifest->variant())->toBe('blade-vue')
        ->and($manifest->adopts())->toBe(['content-pages', 'publishing']);
});

it('is empty (and never deviates) when there is no front matter', function () {
    $manifest = BeamManifest::parse("# Just a heading\n\nno front matter here\n");

    expect($manifest->satellite())->toBeNull()
        ->and($manifest->adopts())->toBe([])
        ->and($manifest->deviations())->toBe([])
        ->and($manifest->deviates('auth.login'))->toBeFalse();
});

it('returns an empty manifest when the file is missing', function () {
    expect(BeamManifest::load(__DIR__.'/does-not-exist/BEAM.md')->toArray())->toBe([]);
});

it('treats an omitted branch as deviating for every flow under it', function () {
    $manifest = manifestFixture(<<<'YAML'
deviations:
  - branch: auth
    status: omitted
    why: marketing site, no end-user accounts
YAML);

    expect($manifest->deviates('auth'))->toBeTrue()
        ->and($manifest->deviates('auth.login'))->toBeTrue()
        ->and($manifest->deviates('auth.register'))->toBeTrue()
        ->and($manifest->deviates('billing.subscribe'))->toBeFalse();
});

it('treats a partial branch as deviating only for its named flows', function () {
    $manifest = manifestFixture(<<<'YAML'
deviations:
  - branch: billing
    status: partial
    flows: [gifting]
    why: no gifting in this brand
YAML);

    expect($manifest->deviates('billing.gifting'))->toBeTrue()
        ->and($manifest->deviates('billing.subscribe'))->toBeFalse()
        ->and($manifest->deviates('billing'))->toBeTrue();
});

// browser-journeys 07 — a Deviation covers its descendants, matched by whole segment.
// This is the mutation guard: reverting flowCovers() to an exact `in_array` match turns the
// descendant assertions below red.
it('covers every descendant of a named flow, by whole segment', function () {
    $manifest = manifestFixture(<<<'YAML'
deviations:
  - branch: account
    status: partial
    flows: [security]
YAML);

    expect($manifest->deviates('account.security'))->toBeTrue()
        ->and($manifest->deviates('account.security.login'))->toBeTrue()
        ->and($manifest->deviates('account.security.passkeys'))->toBeTrue()
        // whole segment: a sibling segment and a shorter stub both miss.
        ->and($manifest->deviates('account.securityx'))->toBeFalse()
        ->and($manifest->deviates('account.sec'))->toBeFalse()
        ->and($manifest->deviates('account.profile'))->toBeFalse();
});

it('accepts a flows entry that itself holds dots', function () {
    $manifest = manifestFixture(<<<'YAML'
deviations:
  - branch: account
    status: partial
    flows: [security.login]
YAML);

    expect($manifest->deviates('account.security.login'))->toBeTrue()
        ->and($manifest->deviates('account.security.login.totp'))->toBeTrue()
        // the stub above it is not itself covered; a sibling flow is not covered.
        ->and($manifest->deviates('account.security'))->toBeFalse()
        ->and($manifest->deviates('account.security.register'))->toBeFalse();
});

it('matches any Deviation on a branch when queried with a bare surface (compatibility rule)', function () {
    $manifest = manifestFixture(<<<'YAML'
deviations:
  - branch: billing
    status: partial
    flows: [gifting]
YAML);

    // A bare `surface` with no dot matches a partial Deviation on that branch — the pre-relocation
    // compatibility rule, kept so a shipped test that skips on the whole surface still skips.
    expect($manifest->deviates('billing'))->toBeTrue();
});

it('parses and exposes an optional package on a Deviation entry', function () {
    // browser-journeys 02 R3-Q3: the doctor reads `package:` to tell a typo from a removed package's
    // flow once `composer remove` has forgotten the registrant. The reader preserves it verbatim.
    $manifest = manifestFixture(<<<'YAML'
deviations:
  - branch: account
    status: partial
    flows: [security.hardware-keys]
    package: acme/laravel-hardware-keys
YAML);

    expect($manifest->deviations())->toHaveCount(1)
        ->and($manifest->deviations()[0]['package'])->toBe('acme/laravel-hardware-keys')
        ->and($manifest->deviates('account.security.hardware-keys'))->toBeTrue();
});

it('exposes deltas written back by the Sweep', function () {
    $manifest = manifestFixture(<<<'YAML'
deltas:
  - branch: back-office
    detected: 2026-07-08
    note: no operator surface present
YAML);

    expect($manifest->deltas())->toHaveCount(1)
        ->and($manifest->deltas()[0]['branch'])->toBe('back-office');
});

it('exposes beam_manifest(), manifest() and manifest_deviates() as global helpers', function () {
    app()->instance(BeamManifest::class, manifestFixture(<<<'YAML'
satellite: numero
deviations:
  - branch: billing
    status: partial
    flows: [gifting]
YAML));

    expect(beam_manifest())->toBeInstanceOf(BeamManifest::class)
        ->and(beam_manifest()->satellite())->toBe('numero')
        ->and(manifest_deviates('billing.gifting'))->toBeTrue()
        ->and(manifest_deviates('auth.login'))->toBeFalse();
});

it('does not fall back to a legacy SATELLITE.md — the rename contracted', function () {
    $dir = sys_get_temp_dir().'/beam-manifest-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/SATELLITE.md', "---\nsatellite: legacy\n---\n");

    try {
        expect(BeamManifest::resolvePath($dir))->toBe($dir.'/BEAM.md')
            ->and(BeamManifest::load(BeamManifest::resolvePath($dir))->satellite())->toBeNull();
    } finally {
        unlink($dir.'/SATELLITE.md');
        rmdir($dir);
    }
});
