<?php

use Schemastud\Frame\Contracts\WidgetLabel;
use Schemastud\Frame\Registry\ContextManifest;
use Splicewire\Beam\Localization\BeamWidgetLabel;
use Splicewire\Beam\Tests\Fixtures\Localization\RouteRowData;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 10 — a list's column headers read in the request's language, by ruling 01 §6: at each locale (outer) and
 * realm rung (inner), the context key, then ONLY for a column with no literal of its own the property's title key. A
 * column whose literal equals its title counts as declaring none. When every rung misses, the declared label stands
 * (and a column with none stays label-less, so the client shows the schema title, as today).
 *
 * Done-when #1 asks for the pseudo-locale (localization 06); proven here with a fixture second locale, and the
 * pseudo-locale assertion carries forward to 06/20.
 */

const ROUTE_ROW = 'Splicewire/Beam/Tests/Fixtures/Localization/RouteRowData';

function columnLang(array $lines): void
{
    $root = sys_get_temp_dir().'/beam-columns-'.getmypid().'-'.bin2hex(random_bytes(4));
    foreach ($lines as $path => $content) {
        @mkdir(dirname("{$root}/{$path}"), 0777, true);
        file_put_contents("{$root}/{$path}", '<?php return '.var_export($content, true).';');
    }
    app()->useLangPath($root);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');
}

/** @return array<string, ?string> field => list-column label (null when none is projected) */
function columnLabels(): array
{
    $byNode = (new ContextManifest)->forResource(RouteRowData::class)['byNode'];

    return array_map(fn (array $contexts): ?string => $contexts['list-column']['label'] ?? null, $byNode);
}

it('binds laravel-frame\'s label hook to beam', function (): void {
    expect(app(WidgetLabel::class))->toBeInstanceOf(BeamWidgetLabel::class);
});

it('projects exactly the declared labels when no line exists', function (): void {
    columnLang([]);

    expect(columnLabels())->toMatchArray(['target' => null, 'path' => 'From', 'state' => 'Status']);
});

it('gives a column with no label of its own the property\'s translated title', function (): void {
    columnLang(['vendor/beam/es/data.php' => [ROUTE_ROW => ['properties' => ['target' => ['title' => 'Destino']]]]]);
    app()->setLocale('es');

    expect(columnLabels()['target'])->toBe('Destino');
});

it('never gives a column with its own label the property\'s title', function (): void {
    columnLang(['vendor/beam/es/data.php' => [ROUTE_ROW => ['properties' => ['path' => ['title' => 'Origen']]]]]);
    app()->setLocale('es');

    expect(columnLabels()['path'])->toBe('From');
});

it('treats a label equal to its title as no label, so it takes the title\'s translation', function (): void {
    columnLang(['vendor/beam/es/data.php' => [ROUTE_ROW => ['properties' => ['state' => ['title' => 'Estado']]]]]);
    app()->setLocale('es');

    expect(columnLabels()['state'])->toBe('Estado');
});

it('prefers the context key to the title key at the same rung', function (): void {
    columnLang(['vendor/beam/es/data.php' => [ROUTE_ROW => ['properties' => ['target' => [
        'title' => 'Destino',
        'contexts' => ['list-column' => ['label' => 'Hacia']],
    ]]]]]);
    app()->setLocale('es');

    expect(columnLabels()['target'])->toBe('Hacia');
});

it('keeps the locale outer: a title in my language beats a context line in English', function (): void {
    columnLang([
        'vendor/beam/es/data.php' => [ROUTE_ROW => ['properties' => ['target' => ['title' => 'Destino']]]],
        'vendor/beam/en/data.php' => [ROUTE_ROW => ['properties' => ['target' => ['contexts' => ['list-column' => ['label' => 'Goes to']]]]]],
    ]);
    app()->setLocale('es');

    expect(columnLabels()['target'])->toBe('Destino');
});

it('translates a column\'s own label through its context key', function (): void {
    columnLang(['vendor/beam/es/data.php' => [ROUTE_ROW => ['properties' => ['path' => ['contexts' => ['list-column' => ['label' => 'Desde']]]]]]]);
    app()->setLocale('es');

    expect(columnLabels()['path'])->toBe('Desde');
});

it('marks every projected column label under the pseudo-locale (localization 06, the 10 carry-forward)', function (): void {
    columnLang([]);
    config(['beam.localization.pseudo' => true]);
    app()->setLocale(Splicewire\Beam\Localization\Locales::PSEUDO);

    $labels = columnLabels();

    expect(Splicewire\Beam\Localization\PseudoLocale::isMarked((string) $labels['path']))->toBeTrue()
        ->and(Splicewire\Beam\Localization\PseudoLocale::isMarked((string) $labels['state']))->toBeTrue()
        // A label-less column stays label-less, so the client still shows the (marked) schema title.
        ->and($labels['target'])->toBeNull();
});
