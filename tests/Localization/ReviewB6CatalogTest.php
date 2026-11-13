<?php

use Illuminate\Support\Arr;
use Splicewire\Beam\Localization\BeamWidgetLabel;
use Splicewire\Beam\Localization\Catalog\KeyCatalog;
use Splicewire\Beam\Localization\DeclaredText;
use Splicewire\Beam\Localization\TranslatedPropertyTextStrategy;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\Localization\AmendChildData;
use Splicewire\Beam\Tests\Fixtures\Localization\AmendParentData;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * REVIEW-B6 B3 (Codex, review-r1) — the key catalog lists the keys the emit hooks resolve: an inherited property under
 * its DECLARING class (as TranslatedPropertyTextStrategy keys it), and a label-less context key (which BeamWidgetLabel
 * tries first). Otherwise `beam:lang:keys`/`missing` omit text the host emits, coverage overstates, and underived-key
 * nominates valid lines.
 */

function b6cLang(array $files): void
{
    $root = sys_get_temp_dir().'/beam-b6c-'.getmypid().'-'.bin2hex(random_bytes(4));
    foreach ($files as $path => $content) {
        @mkdir(dirname("{$root}/{$path}"), 0777, true);
        file_put_contents("{$root}/{$path}", '<?php return '.var_export($content, true).';');
    }
    app()->useLangPath($root);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');
}

/** A host exposing only AmendChildData as a framed resource. */
function b6cHost(): void
{
    app()->instance(ParticleResourceRegistry::class, $resources = new ParticleResourceRegistry);
    app()->instance(ParticleOperationRegistry::class, new ParticleOperationRegistry);
    $resources->register(new ParticleResource(
        key: 'amend', backing: Illuminate\Foundation\Auth\User::class, data: AmendChildData::class,
        label: 'Amends', singularLabel: 'Amend', readOnly: true, frame: true,
    ), by: 'class:'.AmendChildData::class);
}

describe('B3: the catalog lists what the emit hooks resolve', function (): void {
    it('lists an inherited property under its declaring class, which the schema strategy resolves', function (): void {
        b6cHost();
        $property = new ReflectionProperty(AmendChildData::class, 'accountName');
        $key = app(DeclaredText::class)->key('data', AmendParentData::class, 'properties.accountName.title');
        [$ns, $rest] = explode('::', (string) $key, 2);
        $lines = [];
        Arr::set($lines, substr($rest, strlen('data.')), 'Nombre');
        b6cLang(["vendor/{$ns}/es/data.php" => $lines]);
        app()->setLocale('es');

        $schema = app(TranslatedPropertyTextStrategy::class)->apply($property, ['title' => 'Account name'],
            new Schemastud\DataSchemas\Strategies\SchemaStrategyContext([], 'response'));
        $entry = app(KeyCatalog::class)->byKey()[$key] ?? null;

        expect($schema['title'])->toBe('Nombre')
            ->and($entry)->not->toBeNull()
            ->and($entry?->english)->toBe('Account name');
    });

    it('lists a label-less column context key, which the widget-label hook tries first', function (): void {
        b6cHost();
        $property = new ReflectionProperty(AmendChildData::class, 'displayName');
        $key = app(DeclaredText::class)->key('data', AmendChildData::class, 'properties.displayName.contexts.list-column.label');
        [$ns, $rest] = explode('::', (string) $key, 2);
        $lines = [];
        Arr::set($lines, substr($rest, strlen('data.')), 'Nombre visible');
        b6cLang(["vendor/{$ns}/es/data.php" => $lines]);
        app()->setLocale('es');

        $entry = app(KeyCatalog::class)->byKey()[$key] ?? null;

        expect(app(BeamWidgetLabel::class)->label($property, 'list-column', null))->toBe('Nombre visible')
            ->and($entry)->not->toBeNull()
            // Its English is what renders when no line exists: the property's title (ticket 10's chain), a default only.
            ->and($entry?->english)->toBe('Display name');
    });

    it('lists exactly the keys the hooks resolve for the fixture, so an export misses none', function (): void {
        b6cHost();
        b6cLang([]);
        $text = app(DeclaredText::class);
        $expected = [
            $text->key('data', AmendParentData::class, 'properties.accountName.title'),
            $text->key('data', AmendChildData::class, 'properties.displayName.title'),
            $text->key('data', AmendChildData::class, 'properties.displayName.contexts.list-column.label'),
        ];

        $keys = array_keys(app(KeyCatalog::class)->byKey());

        foreach ($expected as $key) {
            expect($keys)->toContain($key);
        }
        // An inherited property is never listed under the child that merely exposes it.
        expect($keys)->not->toContain($text->key('data', AmendChildData::class, 'properties.accountName.title'));
    });
});
