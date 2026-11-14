<?php

use Splicewire\Beam\Localization\Client\DiscoveredCatalog;
use Splicewire\Beam\Localization\Client\DiscoveryState;
use Splicewire\Beam\Localization\Client\Jcs;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 16: the ONE reader of the host's discovered client catalog (ruling 02 §3). discovered-catalog.json was
 * written by schemastud's own jcs.mjs (6c22dbf4) exactly as its collector writes, so its digest is JS-produced and this
 * reader proves it verifies what the real tool writes. A missing or invalid catalog is an explicit state, never empty.
 */

function discoveredFixture(): string
{
    return dirname(__DIR__).'/Fixtures/Localization/discovered-catalog.json';
}

/** A temp artifact: $mutate edits the decoded document; $redigest recomputes the digest afterwards. */
function discoveredVariant(callable $mutate, bool $redigest = true): string
{
    $document = json_decode((string) file_get_contents(discoveredFixture()), true);
    $mutate($document);
    if ($redigest) {
        $document['digest'] = 'sha256:'.Jcs::hash(json_decode(json_encode($document['payload']), false));
    }
    $path = sys_get_temp_dir().'/beam-discovered-'.getmypid().'-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($path, Jcs::canonicalize(json_decode(json_encode($document), false))."\n");

    return $path;
}

it('reads the collector-written artifact, re-verifying its JS-produced digest', function (): void {
    $catalog = DiscoveredCatalog::read(discoveredFixture());

    expect($catalog->state)->toBe(DiscoveryState::Valid)
        ->and($catalog->payloadDigest)->toBe('sha256:e5f2c642ce1c64cefd2fedac198453e4c312d79b25d2765bc56fc590f8a4987e')
        ->and($catalog->namespaces())->toBe(['frame', 'schemastud-ui'])
        ->and($catalog->defaults['schemastud-ui::messages.save'])->toBe(['default' => 'Save', 'owner' => '@schemastud/ui'])
        ->and($catalog->defaults['messages.profile_updated']['default'])->toBe('Profile updated.')
        ->and($catalog->packages[0]['sharesWith'])->toBe('schemastud/laravel-frame');
});

it('reports a missing catalog as MISSING, never as an empty success', function (): void {
    $catalog = DiscoveredCatalog::read('/nonexistent/resources/beam/messages.json');

    expect($catalog->state)->toBe(DiscoveryState::Missing)
        ->and($catalog->problem)->toContain('schemastud-messages collect')
        ->and($catalog->defaults)->toBe([]);
});

it('refuses an artifact whose digest no longer matches its payload', function (): void {
    $path = discoveredVariant(function (array &$d): void {
        $d['payload']['messages']['schemastud-ui::messages.save']['default'] = 'Store';
    }, redigest: false);

    $catalog = DiscoveredCatalog::read($path);
    expect($catalog->state)->toBe(DiscoveryState::Invalid)->and($catalog->problem)->toContain('digest');
});

it('refuses a bad shape even with a matching digest', function (callable $mutate, string $why): void {
    $catalog = DiscoveredCatalog::read(discoveredVariant($mutate));

    expect($catalog->state)->toBe(DiscoveryState::Invalid)->and($catalog->problem)->toContain($why);
})->with([
    'version 2' => [function (array &$d): void {
        $d['payload']['version'] = 2;
    }, 'version 1'],
    'undeclared namespace' => [function (array &$d): void {
        $d['payload']['messages']['nope::messages.x'] = ['default' => 'X', 'owner' => 'p'];
    }, 'no declared namespace'],
    'reserved group' => [function (array &$d): void {
        $d['payload']['messages']['frame::nav.x'] = ['default' => 'X', 'owner' => 'p'];
    }, 'grammar'],
    'non-string default' => [function (array &$d): void {
        $d['payload']['messages']['frame::messages.close']['default'] = 3;
    }, '{default, owner}'],
    'bad namespace' => [function (array &$d): void {
        $d['payload']['packages'][0]['namespace'] = 'Frame!';
    }, 'Package #0'],
]);

it('refuses JSON the bridge never admits (a duplicate key), whatever its digest', function (): void {
    $path = sys_get_temp_dir().'/beam-discovered-dup-'.getmypid().'.json';
    file_put_contents($path, '{"digest":"sha256:'.str_repeat('0', 64).'","digest":"x","payload":{}}');

    expect(DiscoveredCatalog::read($path)->state)->toBe(DiscoveryState::Invalid);
});
