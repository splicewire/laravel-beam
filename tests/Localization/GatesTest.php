<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\LocalizationCoverageAudit;
use Splicewire\Beam\Doctor\LocalizationEnglishDerivationAudit;
use Splicewire\Beam\Doctor\LocalizationLangFileAudit;
use Splicewire\Beam\Doctor\LocalizationUnresolvedLiteralKeyAudit;
use Splicewire\Beam\Localization\Catalog\KeyCatalog;
use Splicewire\Beam\Localization\Catalog\SourceScanner;
use Splicewire\Beam\Localization\TextNamespaces;
use Splicewire\Beam\Particle\ActionAffordance;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Tests\Fixtures\Localization\ReminderData;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 12 — the gates. The key catalog, the two commands and each doctor audit, with one fixture each fails
 * and one it passes; every command and audit names the population it read (PHP-emitted keys only, until 16).
 */

function gatesTmp(): string
{
    $dir = sys_get_temp_dir().'/beam-gates-'.getmypid().'-'.bin2hex(random_bytes(4));
    @mkdir($dir, 0777, true);

    return $dir;
}

/** @param  array<string, array<string, mixed>>  $files  path under the lang root => lines */
function gatesLang(array $files): string
{
    $root = gatesTmp();
    foreach ($files as $path => $content) {
        @mkdir(dirname("{$root}/{$path}"), 0777, true);
        file_put_contents("{$root}/{$path}", '<?php return '.var_export($content, true).';');
    }
    app()->useLangPath($root);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');

    return $root;
}

/** A host with exactly one framed resource, so the catalog's derived population is known. */
function gatesHost(string $label = 'Reminders', string $singular = 'Reminder', ?string $actionLabel = 'Snooze'): void
{
    app()->instance(ParticleResourceRegistry::class, $resources = new ParticleResourceRegistry);
    app()->instance(ParticleOperationRegistry::class, $operations = new ParticleOperationRegistry);
    $resources->register(new ParticleResource(
        key: 'reminders', backing: Illuminate\Foundation\Auth\User::class, data: ReminderData::class,
        label: $label, singularLabel: $singular, readOnly: true, frame: true,
    ), by: 'class:'.ReminderData::class);
    $operations->register(new ParticleOperation(
        resource: 'reminders', name: 'snooze', kind: OperationKind::Write, handle: fn () => [],
        ability: false, abilityModel: false, input: false, subject: NoSubject::class,
        affordance: new ActionAffordance(label: $actionLabel),
    ), by: 'class:'.ReminderData::class);
}

/** @param  list<Finding>  $findings */
function statuses(array $findings, string $check): array
{
    // An inconclusive finding is a nonblocking Pass with `conclusive: false`; name it, so it never reads as a clean pass.
    return array_values(array_map(
        fn (Finding $f): string => $f->conclusive ? $f->status->value : 'inconclusive',
        array_filter($findings, fn (Finding $f): bool => $f->check === $check),
    ));
}

/** @param  list<Finding>  $findings */
function details(array $findings): string
{
    return implode("\n", array_map(fn (Finding $f): string => $f->detail, $findings));
}

describe('the catalog', function (): void {
    it('lists derived keys with their English and the explicit keys of family lang files', function (): void {
        gatesLang([]);
        gatesHost();

        $keys = app(KeyCatalog::class)->byKey();

        expect($keys['beam::resources.reminders.label']->english)->toBe('Reminders')
            ->and($keys['beam::resources.reminders.singular']->english)->toBe('Reminder')
            ->and($keys['beam::actions.reminders.snooze.label']->english)->toBe('Snooze')
            ->and($keys['beam::data.Splicewire/Beam/Tests/Fixtures/Localization/ReminderData.properties.subject.title']->english)->toBe('Subject')
            ->and($keys['beam::enums.Splicewire/Beam/Tests/Fixtures/Localization/CadenceEnum.cases.Daily.label']->english)->toBe('Every day')
            // a coded error, found in family source
            ->and($keys['beam::enums.Splicewire/Beam/Webhooks/HookSubscriptionError.cases.EventsRequired.message']->english)
            ->toBe('Name at least one event to subscribe to. GET hooks/events lists them.')
            // an explicit key from a family package's lang/en (laravel-data-filters ships one)
            ->and($keys['data-filters::options.boolean.true']->source)->toBe('explicit');
    });

    it('marks an English derivation as one', function (): void {
        gatesLang([]);
        gatesHost(singular: '', actionLabel: null);

        $keys = app(KeyCatalog::class)->byKey();

        expect($keys['beam::resources.reminders.singular']->english)->toBe('Reminder')
            ->and($keys['beam::resources.reminders.singular']->derivedEnglish)->toBeTrue()
            ->and($keys['beam::actions.reminders.snooze.label']->english)->toBe('Snooze')
            ->and($keys['beam::actions.reminders.snooze.label']->derivedEnglish)->toBeTrue()
            ->and($keys['beam::resources.reminders.label']->derivedEnglish)->toBeFalse();
    });
});

describe('the commands', function (): void {
    it('lists every key with its English, and says its population', function (): void {
        gatesLang([]);
        gatesHost();

        Artisan::call('beam:lang:keys', ['--json' => true]);
        $json = json_decode(Artisan::output(), true);

        expect($json['population'])->toBe(KeyCatalog::POPULATION)
            ->and($json['population'])->toContain('PHP-emitted keys only')
            ->and(array_column($json['entries'], 'english', 'key'))->toHaveKey('beam::resources.reminders.label', 'Reminders');
    });

    it('exports exactly the keys a fixture locale is missing', function (): void {
        gatesLang([]);
        gatesHost();
        $all = app(KeyCatalog::class)->entries();
        $leftOut = ['beam::resources.reminders.singular', 'beam::actions.reminders.snooze.label'];

        // Spanish lines for every key but two.
        $files = [];
        foreach ($all as $entry) {
            if (in_array($entry->key, $leftOut, true)) {
                continue;
            }
            $ns = $entry->namespace();
            $rest = $ns === null ? $entry->key : substr($entry->key, strlen($ns) + 2);
            $group = strtok($rest, '.');
            $path = $ns === null ? "es/{$group}.php" : "vendor/{$ns}/es/{$group}.php";
            Arr::set($files[$path], substr($rest, strlen($group) + 1), 'es: '.$entry->english);
        }
        gatesLang($files);
        app(TextNamespaces::class)->familyNamespaces(); // the catalog hints each namespace on use
        config(['beam.localization.supported' => ['en', 'es']]);

        Artisan::call('beam:lang:missing', ['locale' => 'es', '--json' => true]);
        $json = json_decode(Artisan::output(), true);

        expect(array_column($json['missing'], 'key'))->toEqualCanonicalizing($leftOut)
            ->and($json['population'])->toBe(KeyCatalog::POPULATION);
    });

    it('exports nothing for the pseudo-locale, by construction', function (): void {
        gatesLang([]);
        gatesHost();
        config(['beam.localization.pseudo' => true]);

        Artisan::call('beam:lang:missing', ['locale' => 'en-XA', '--json' => true]);
        $json = json_decode(Artisan::output(), true);

        expect($json['missing'])->toBe([])
            ->and($json['note'])->toContain('by construction');
    });
});

describe('the audits', function (): void {
    it('reports a literal family key that resolves nowhere, and passes one that resolves', function (): void {
        gatesLang(['vendor/beam/en/gates.php' => ['hello' => 'Hello']]);
        $host = gatesTmp();
        file_put_contents("{$host}/Good.php", "<?php echo __('beam::gates.hello');");
        $audit = new LocalizationUnresolvedLiteralKeyAudit(app(TextNamespaces::class), [$host]);
        $passing = $audit->run();

        file_put_contents("{$host}/Bad.php", "<?php echo __('beam::gates.helo'); echo __('spatie-thing::x.y');");
        $failing = $audit->run();

        expect(statuses($passing, LocalizationUnresolvedLiteralKeyAudit::CHECK))->toBe(['pass'])
            ->and(details($passing))->toContain('PHP-emitted keys only')
            ->and(statuses($failing, LocalizationUnresolvedLiteralKeyAudit::CHECK))->toBe(['warn'])
            ->and(details($failing))->toContain('beam::gates.helo')->not->toContain('spatie-thing');
    });

    it('reports a realm line with no base and a derived line no declaration derives, and passes clean files', function (): void {
        gatesHost();
        gatesLang([
            'vendor/beam/es/resources.php' => ['reminders' => ['label' => 'Recordatorios']],
            'vendor/beam/es/tenant/resources.php' => ['reminders' => ['label' => 'Mis recordatorios']],
        ]);
        $clean = app(LocalizationLangFileAudit::class)->run();

        gatesLang([
            'vendor/beam/es/tenant/messages.php' => ['only_here' => 'Sólo aquí'],
            'vendor/beam/es/resources.php' => ['remindres' => ['label' => 'Recordatorios']],
        ]);
        $dirty = app(LocalizationLangFileAudit::class)->run();

        expect(statuses($clean, LocalizationLangFileAudit::REALM))->toBe(['pass'])
            ->and(statuses($clean, LocalizationLangFileAudit::UNDERIVED))->toBe(['pass'])
            ->and(details($clean))->toContain('PHP-emitted keys only')
            ->and(statuses($dirty, LocalizationLangFileAudit::REALM))->toBe(['warn'])
            ->and(details($dirty))->toContain('beam::messages.only_here')
            ->and(statuses($dirty, LocalizationLangFileAudit::UNDERIVED))->toBe(['warn'])
            ->and(details($dirty))->toContain('beam::resources.remindres.label');
    });

    it('measures coverage per locale, warning below full and passing at full', function (): void {
        gatesHost();
        gatesLang([]);
        config(['beam.localization.supported' => ['en', 'es']]);
        $partial = app(LocalizationCoverageAudit::class)->run();

        $files = [];
        foreach (app(KeyCatalog::class)->entries() as $entry) {
            $ns = $entry->namespace();
            $rest = $ns === null ? $entry->key : substr($entry->key, strlen($ns) + 2);
            $group = strtok($rest, '.');
            Arr::set($files[$ns === null ? "es/{$group}.php" : "vendor/{$ns}/es/{$group}.php"], substr($rest, strlen($group) + 1), 'es');
        }
        gatesLang($files);
        $full = app(LocalizationCoverageAudit::class)->run();

        config(['beam.localization.supported' => ['en']]);
        $none = app(LocalizationCoverageAudit::class)->run();

        expect(statuses($partial, LocalizationCoverageAudit::CHECK))->toBe(['warn'])
            ->and(details($partial))->toContain('beam:lang:missing es')
            ->and(statuses($full, LocalizationCoverageAudit::CHECK))->toBe(['pass'])
            ->and(details($full))->toContain('PHP-emitted keys only')
            ->and(statuses($none, LocalizationCoverageAudit::CHECK))->toBe(['inconclusive']);
    });

    it('reports a framed label that relies on English inflection, and passes declared ones', function (): void {
        gatesLang([]);
        gatesHost();
        $declared = app(LocalizationEnglishDerivationAudit::class)->run();

        gatesHost(singular: '', actionLabel: null);
        $derived = app(LocalizationEnglishDerivationAudit::class)->run();

        expect(statuses($declared, LocalizationEnglishDerivationAudit::CHECK))->toBe(['pass'])
            ->and(details($declared))->toContain('PHP-emitted keys only')
            ->and(statuses($derived, LocalizationEnglishDerivationAudit::CHECK))->toBe(['warn', 'warn'])
            ->and(details($derived))->toContain('beam::resources.reminders.singular')->toContain('beam::actions.reminders.snooze.label');
    });
});

it('reads literal keys from PHP and Blade, skipping a concatenated prefix', function (): void {
    $dir = gatesTmp();
    file_put_contents("{$dir}/a.php", "<?php __('beam::a.b'); trans_choice(\"beam::c.d\", 2); Lang::get('beam::e.f'); __('beam::prefix.'.\$x); \$o->__('beam::not.a.call'); __('Sentence');");
    file_put_contents("{$dir}/v.blade.php", "@lang('beam::g.h') {{ __('beam::i.j') }}");

    expect(array_column(SourceScanner::literalKeys("{$dir}/a.php"), 'key'))->toBe(['beam::a.b', 'beam::c.d', 'beam::e.f'])
        ->and(array_column(SourceScanner::literalKeys("{$dir}/v.blade.php"), 'key'))->toBe(['beam::g.h', 'beam::i.j']);
});
