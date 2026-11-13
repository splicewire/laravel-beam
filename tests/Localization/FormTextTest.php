<?php

use Schemastud\DataSchemas\Contracts\SchemaText;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Splicewire\Beam\Localization\BeamSchemaText;
use Splicewire\Beam\Tests\Fixtures\Localization\CadenceEnum;
use Splicewire\Beam\Tests\Fixtures\Localization\ReminderData;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 09 — a form's text reads in the request's language: a property's title and description (beam's strategy),
 * a class's title and description, an enum's title and its case labels (laravel-data-schemas' hook, bound by beam), each
 * through its derived key (ruling 01 §2) and with the declaration as the default.
 *
 * Done-when #1 asks for the pseudo-locale, which ships with localization 06; it is proven here with a fixture second
 * locale, and the pseudo-locale assertion carries forward to 06/20.
 */

function formTextLang(array $lines): void
{
    $root = sys_get_temp_dir().'/beam-form-'.getmypid().'-'.bin2hex(random_bytes(4));
    foreach ($lines as $path => $content) {
        @mkdir(dirname("{$root}/{$path}"), 0777, true);
        file_put_contents("{$root}/{$path}", '<?php return '.var_export($content, true).';');
    }
    app()->useLangPath($root);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');
}

function reminderSchema(): array
{
    return (new JsonSchemaGenerator)->generate(new ReflectionClass(ReminderData::class));
}

it('binds laravel-data-schemas\' text hook to beam', function (): void {
    expect(app(SchemaText::class))->toBeInstanceOf(BeamSchemaText::class);
});

it('keeps every declared English text when no line exists', function (): void {
    formTextLang([]);
    $schema = reminderSchema();

    expect($schema['title'])->toBe('Reminder')
        ->and($schema['description'])->toBe('A note that comes back.')
        ->and($schema['properties']['subject']['title'])->toBe('Subject')
        ->and($schema['properties']['subject']['description'])->toBe('What the reminder is about.')
        ->and($schema['$defs']['CadenceEnum']['enumNames'])->toBe(['Every day', 'Every week']);
});

it('reads a property title, a description and an enum name from a second locale', function (): void {
    $class = 'Splicewire/Beam/Tests/Fixtures/Localization/ReminderData';
    $enum = 'Splicewire/Beam/Tests/Fixtures/Localization/CadenceEnum';
    formTextLang([
        'vendor/beam/es/data.php' => [$class => [
            'title' => 'Recordatorio',
            'properties' => ['subject' => ['title' => 'Asunto', 'description' => 'De qué trata el recordatorio.']],
        ]],
        'vendor/beam/es/enums.php' => [$enum => ['cases' => ['Daily' => ['label' => 'Cada día']]]],
    ]);
    app()->setLocale('es');

    $schema = reminderSchema();

    expect($schema['title'])->toBe('Recordatorio')
        ->and($schema['properties']['subject']['title'])->toBe('Asunto')
        ->and($schema['properties']['subject']['description'])->toBe('De qué trata el recordatorio.')
        ->and($schema['properties']['cadence']['title'] ?? null)->toBe('Repeats')
        ->and($schema['$defs']['CadenceEnum']['enumNames'])->toBe(['Cada día', 'Every week'])
        ->and($schema['description'])->toBe('A note that comes back.');
});

it('answers an enum case label outside schema generation, for a filter (ticket 14)', function (): void {
    formTextLang([
        'vendor/beam/es/enums.php' => ['Splicewire/Beam/Tests/Fixtures/Localization/CadenceEnum' => ['cases' => ['Weekly' => ['label' => 'Cada semana']]]],
    ]);
    app()->setLocale('es');

    expect(app(SchemaText::class)->enumCaseLabel(CadenceEnum::Weekly, CadenceEnum::Weekly->label()))->toBe('Cada semana')
        ->and(app(SchemaText::class)->enumCaseLabel(CadenceEnum::Daily, CadenceEnum::Daily->label()))->toBe('Every day');
});

it('marks every title, description and enum name under the pseudo-locale (localization 06, the 09 carry-forward)', function (): void {
    formTextLang([]);
    config(['beam.localization.pseudo' => true]);
    app()->setLocale(Splicewire\Beam\Localization\Locales::PSEUDO);

    $schema = reminderSchema();
    $marked = fn (?string $text): bool => $text !== null && Splicewire\Beam\Localization\PseudoLocale::isMarked($text);

    expect($marked($schema['title']))->toBeTrue()
        ->and($marked($schema['description']))->toBeTrue()
        ->and($marked($schema['properties']['subject']['title']))->toBeTrue()
        ->and($marked($schema['properties']['subject']['description']))->toBeTrue()
        ->and(array_map($marked, $schema['$defs']['CadenceEnum']['enumNames']))->toBe([true, true]);
});
