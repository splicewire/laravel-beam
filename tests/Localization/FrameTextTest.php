<?php

namespace Splicewire\Beam\Tests\Localization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Registry\ResourceDefinition;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Localization\DeclaredText;
use Splicewire\Beam\Localization\FrameText;
use Splicewire\Beam\Localization\Locales;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Particle\ActionAffordance;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Tests\TestCase;

/**
 * localization 08 — a framed resource's text resolves at emit (the tracer). The manifest's display fields (label,
 * singular, action labels) go through the one resolver with their derived keys (ADR-0244), the declaration as the
 * default, and the manifest's realm; no declaration changes.
 *
 * Fixtures: `lockers` is labelled and declares a singular; `bins` declares no label (frame derives the English);
 * `lockers.empty` is an action with a declared label, `lockers.audit` one whose label is an English derivation.
 * The `ops` realm has a host override for `lockers` ("Ops lockers").
 */
class FrameTextTest extends TestCase
{
    private const LOCKER_DATA = 'Splicewire/Beam/Tests/Localization/FrameTextLockerData';

    private string $lang;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('frame.middleware', []);
        $app['config']->set('beam.core.realm_resource_overrides', ['ops' => ['lockers' => ['label' => 'Ops lockers']]]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['frame_text_lockers', 'frame_text_bins'] as $table) {
            Schema::create($table, function (Blueprint $t): void {
                $t->id();
            });
        }

        $registry = $this->app->make(ParticleResourceRegistry::class);
        $registry->register(new ParticleResource(
            key: 'lockers', backing: FrameTextLocker::class, data: FrameTextLockerData::class,
            label: 'Lockers', singularLabel: 'Locker', readOnly: true,
        ), ['ops', 'tenant']);
        $registry->register(new ParticleResource(
            key: 'bins', backing: FrameTextBin::class, data: FrameTextBinData::class, readOnly: true, frame: true,
        ));

        $operations = $this->app->make(ParticleOperationRegistry::class);
        foreach (['empty' => new ActionAffordance(label: 'Empty locker'), 'audit' => new ActionAffordance] as $name => $affordance) {
            $operations->register(new ParticleOperation(
                resource: 'lockers', name: $name, kind: OperationKind::Write, handle: fn () => ['data' => []],
                ability: false, abilityModel: false, input: false, subject: NoSubject::class, affordance: $affordance,
            ), by: 'class:'.FrameTextLockerData::class);
        }
        Particle::ops('api/lockers', 'lockers', ['empty', 'audit']);

        $this->lang = sys_get_temp_dir().'/beam-frame-text-'.getmypid().'-'.bin2hex(random_bytes(4));
    }

    /** @param  array<string, array<string, mixed>>  $files  path under lang/vendor/beam/ => group lines */
    private function lines(array $files): void
    {
        // A fresh directory per call, so an earlier call's files never answer a later case.
        $this->lang = sys_get_temp_dir().'/beam-frame-text-'.getmypid().'-'.bin2hex(random_bytes(4));
        foreach ($files as $path => $content) {
            @mkdir(dirname("{$this->lang}/vendor/beam/{$path}"), 0777, true);
            file_put_contents("{$this->lang}/vendor/beam/{$path}", '<?php return '.var_export($content, true).';');
        }
        $this->app->useLangPath($this->lang);
        $this->app->forgetInstance('translation.loader');
        $this->app->forgetInstance('translator');
    }

    private function definition(string $key, ?string $realm = null): ResourceDefinition
    {
        return $this->app->make(ParticleResourceRegistry::class)->definition($key, $realm);
    }

    /** @return array<string, string> action key => label */
    private function actionLabels(ResourceDefinition $definition): array
    {
        return collect($definition->actions)->mapWithKeys(fn ($a) => [$a->key => $a->label])->all();
    }

    public function test_the_english_manifest_is_byte_identical_with_no_line(): void
    {
        $this->lines([]);

        $lockers = $this->definition('lockers');
        $bins = $this->definition('bins');

        $this->assertSame('Lockers', $lockers->nav->label);
        $this->assertSame('Locker', $lockers->singularLabel);
        $this->assertSame(['empty' => 'Empty locker', 'audit' => 'Audit'], $this->actionLabels($lockers));
        // A label-less resource stays label-less on the wire; frame derives the same English as before.
        $this->assertSame('', $bins->nav->label);
        $this->assertSame('', $bins->singularLabel);
        $this->assertSame('Bins', $bins->resolvedLabel());
    }

    public function test_a_second_locale_line_changes_one_label_and_nothing_else(): void
    {
        $this->lines(['es/resources.php' => ['lockers' => ['label' => 'Taquillas']]]);
        $this->app->setLocale('es');

        $lockers = $this->definition('lockers');

        $this->assertSame('Taquillas', $lockers->nav->label);
        // No singular line: the English singular is the default, never derived from the Spanish label.
        $this->assertSame('Locker', $lockers->singularLabel);
        // A base line answers every realm that has no rung of its own.
        $this->assertSame('Taquillas', $this->definition('lockers', 'tenant')->nav->label);
    }

    public function test_singular_and_action_labels_resolve_through_their_derived_keys(): void
    {
        $this->lines([
            'es/resources.php' => ['lockers' => ['singular' => 'Taquilla']],
            'es/actions.php' => ['lockers' => ['empty' => ['label' => 'Vaciar taquilla'], 'audit' => ['label' => 'Auditar']]],
        ]);
        $this->app->setLocale('es');

        $lockers = $this->definition('lockers');

        $this->assertSame('Taquilla', $lockers->singularLabel);
        $this->assertSame(['empty' => 'Vaciar taquilla', 'audit' => 'Auditar'], $this->actionLabels($lockers));
    }

    public function test_a_realm_rung_changes_a_label_for_that_realm_only(): void
    {
        $this->lines([
            'es/resources.php' => ['lockers' => ['label' => 'Taquillas']],
            'es/tenant/resources.php' => ['lockers' => ['label' => 'Mis taquillas']],
        ]);
        $this->app->setLocale('es');

        $this->assertSame('Mis taquillas', $this->definition('lockers', 'tenant')->nav->label);
        $this->assertSame('Taquillas', $this->definition('lockers', 'ops')->nav->label);
        $this->assertSame('Taquillas', $this->definition('lockers')->nav->label);
    }

    public function test_a_hosts_existing_realm_override_still_renders_as_it_did_in_english(): void
    {
        $this->lines([]);

        $this->assertSame('Ops lockers', $this->definition('lockers', 'ops')->nav->label);
        $this->assertSame('Lockers', $this->definition('lockers', 'tenant')->nav->label);

        // ...and, being that realm's ENGLISH rung, it yields to a translation (locale is the outer loop).
        $this->lines(['es/resources.php' => ['lockers' => ['label' => 'Taquillas']]]);
        $this->app->setLocale('es');
        $this->assertSame('Taquillas', $this->definition('lockers', 'ops')->nav->label);

        // With no Spanish line, a Spanish request still reads the host's English override, as before.
        $this->lines([]);
        $this->app->setLocale('es');
        $this->assertSame('Ops lockers', $this->definition('lockers', 'ops')->nav->label);
    }

    public function test_no_display_field_is_plain_english_under_the_pseudo_locale(): void
    {
        $this->lines([]);
        config(['beam.localization.pseudo' => true]);
        $this->app->setLocale(Locales::PSEUDO);

        foreach ([$this->definition('lockers'), $this->definition('lockers', 'ops'), $this->definition('bins')] as $definition) {
            $this->assertTrue(PseudoLocale::isMarked($definition->nav->label), $definition->key.' label: '.$definition->nav->label);
            $this->assertTrue(PseudoLocale::isMarked($definition->singularLabel), $definition->key.' singular: '.$definition->singularLabel);
            foreach ($definition->actions as $action) {
                $this->assertTrue(PseudoLocale::isMarked($action->label), $action->key.': '.$action->label);
            }
        }
        // The English derivation is the default only: the marked singular is the English singular, marked.
        $this->assertStringContainsString('Bíñ', $this->definition('bins')->singularLabel);
    }

    public function test_text_resolves_per_request_never_at_registration(): void
    {
        $this->lines(['es/resources.php' => ['lockers' => ['label' => 'Taquillas']]]);

        $this->app->setLocale('es');
        $this->assertSame('Taquillas', $this->definition('lockers')->nav->label);
        $this->app->setLocale('en');
        $this->assertSame('Lockers', $this->definition('lockers')->nav->label);
        // The stored declaration keeps the declared literal.
        $this->assertSame('Lockers', $this->app->make(ParticleResourceRegistry::class)->resolve('lockers')->label);
    }

    public function test_a_group_name_resolves_by_its_key_and_defaults_to_the_headlined_group(): void
    {
        $this->lines(['es/groups.php' => ['access-control' => ['name' => 'Control de acceso']]]);
        $text = $this->app->make(FrameText::class);

        $this->assertSame('Access Control', $text->groupName('access-control', 'splicewire/laravel-beam'));
        $this->app->setLocale('es');
        $this->assertSame('Control de acceso', $text->groupName('access-control', 'splicewire/laravel-beam'));
    }

    public function test_the_resolver_derives_keys_refuses_what_it_cannot_spell_and_replaces_parameters(): void
    {
        $this->assertSame('beam::resources.sitemap/records.label', DeclaredText::derive('beam::', 'resources', ['sitemap.records'], 'label'));
        $this->assertSame('actions.a.b/c.label', DeclaredText::derive('', 'actions', ['a', 'b.c'], 'label'));
        $this->assertNull(DeclaredText::derive(null, 'resources', ['k'], 'label'));
        $this->assertNull(DeclaredText::derive('beam::', 'resources', ['a/b'], 'label'));

        $this->lines(['en/resources.php' => ['lockers' => ['count' => ':count lockers']]]);
        // A namespace is hinted on first use, when an emit point resolves its owner; do the same here.
        $this->assertSame('beam::', $this->app->make(FrameText::class)->prefixFor(null, FrameTextLockerData::class));
        $this->assertSame('3 lockers', $this->app->make(DeclaredText::class)->resolve('beam::resources.lockers.count', ':count fallback', replace: ['count' => 3]));
        $this->assertSame('3 fallback', $this->app->make(DeclaredText::class)->resolve(null, ':count fallback', replace: ['count' => 3]));
    }
}

class FrameTextLocker extends Model
{
    protected $table = 'frame_text_lockers';
}

class FrameTextBin extends Model
{
    protected $table = 'frame_text_bins';
}

class FrameTextLockerData extends Data
{
    public function __construct(public int $id) {}
}

class FrameTextBinData extends Data
{
    public function __construct(public int $id) {}
}
