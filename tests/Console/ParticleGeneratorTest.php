<?php

namespace Splicewire\Beam\Tests\Console;

use App\Models\Lyric;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Splicewire\Beam\Console\ParticleGeneratorCommand;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\CentralPinJustificationAudit;
use Splicewire\Beam\Surgeon\UndeclaredSurfaceAudit;
use Splicewire\Beam\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * The generators' real acceptance test: **a freshly generated surface, mounted on a route, produces NO finding
 * from the conformance audits.** "A file appeared" is not the bar — a scaffolder that emits a working surface
 * with an undeclared shape has reproduced the exact defect it was built to stop propagating.
 *
 * ## Why this test builds a throwaway host
 * `GeneratorCommand` writes to `app_path()` and derives its root namespace by matching `app_path()` against
 * the psr-4 map in the base path's `composer.json`. Pointing only `useAppPath()` at a temp dir breaks that
 * match and `getNamespace()` throws, so each test relocates the WHOLE base path to a temp directory carrying
 * a two-line `composer.json` — a real host as far as the generator can tell, and disposable.
 *
 * The temp path also has to sit OUTSIDE the package tree for the central-pin assertion to mean anything:
 * {@see CentralPinJustificationAudit::DEFAULT_EXCLUDED_PATHS} drops `/tests/`, `/fixtures/` and
 * `/vendor/orchestra/` wholesale, so generating into the testbench skeleton would have made that audit pass by
 * exclusion rather than by conformance. {@see test_the_central_pin_audit_actually_reaches_the_generated_tree}
 * exists to prove the scan is not being silently excluded.
 */
class ParticleGeneratorTest extends TestCase
{
    private string $host;

    protected function setUp(): void
    {
        parent::setUp();

        // No 'test'/'fixture'/'stub' fragment in the name — those are audit-excluded path substrings and would
        // quietly neuter the central-pin assertion below.
        $this->host = sys_get_temp_dir().'/beam-particle-gen-'.Str::random(10);

        File::ensureDirectoryExists($this->host.'/app/Models');
        File::put($this->host.'/composer.json', json_encode(
            ['autoload' => ['psr-4' => ['App\\' => 'app/']]],
            JSON_PRETTY_PRINT,
        ));

        // A model for the generated surface to resolve. Written rather than aliased so the emitted
        // `use App\Models\…;` refers to something real once the generated files are required.
        File::put($this->host.'/app/Models/Lyric.php', <<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Database\Eloquent\Model;

        class Lyric extends Model {}
        PHP);

        $this->app->setBasePath($this->host);

        // Each test gets a FRESH host directory, so `require_once` (which keys on the path, not the class)
        // would redeclare the model on the second test. Guard on the class, not the file.
        if (! class_exists(Lyric::class, false)) {
            require_once $this->host.'/app/Models/Lyric.php';
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->host);

        parent::tearDown();
    }

    // ── what gets emitted ───────────────────────────────────────────────────────────────────────────

    public function test_a_generated_resource_presents_its_derived_label_and_a_list_column(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'ScaffoldPack', '--model' => 'Lyric'])
            ->expectsOutputToContain('no findings')->assertSuccessful();

        $resource = $this->app->make(ParticleResourceRegistry::class)->definition('scaffold-packs');
        $this->assertSame('Scaffold packs', $resource->nav->label);
        $block = (new \Schemastud\Frame\Registry\ContextManifest)->forResource($resource->data);
        $this->assertArrayHasKey('list-column', $block['byNode']['id']);
    }

    public function test_a_label_override_and_an_explicitly_empty_label_are_checked(): void
    {
        foreach (['NamedFrame' => 'Our records', 'HiddenFrame' => ''] as $name => $label) {
            $this->artisan('splicewire:beam:make:particle-resource', ['name' => $name, '--model' => 'Lyric', '--label' => $label])
                ->expectsOutputToContain('no findings')->assertSuccessful();
            $this->assertStringContainsString('label: '.var_export($label, true).',', $this->read('app/Data/'.$name.'Data.php'));
        }
        $this->assertStringContainsString('addressable and not presented', $this->read('app/Data/HiddenFrameData.php'));
    }

    public function test_a_published_resource_stub_missing_its_label_or_column_fails_verification(): void
    {
        $stub = File::get(dirname(__DIR__, 2).'/stubs/particle-resource.stub');
        File::ensureDirectoryExists($this->host.'/stubs');
        foreach (['UnlabelledFrame' => '    label: {{ label }},', 'ColumnlessFrame' => '        #[Column]'] as $name => $removed) {
            File::put($this->host.'/stubs/particle-resource.stub', str_replace($removed, '', $stub));
            $this->artisan('splicewire:beam:make:particle-resource', ['name' => $name, '--model' => 'Lyric'])
                ->assertFailed();
        }
    }

    public function test_the_resource_generator_emits_a_read_data_class_and_the_write_dto_its_input_slot_names(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Lyric'])->assertSuccessful();

        // The `Data` suffix is forced: the attribute belongs on a Data class, and the suffix is also what makes
        // the read class / model short-name collision structurally unreachable.
        $read = $this->read('app/Data/LyricData.php');
        $input = $this->read('app/Data/LyricInputData.php');

        $this->assertStringContainsString("key: 'lyrics',", $read);
        $this->assertStringContainsString('backing: \\Splicewire\\Beam\\Particle\\Backing\\ActorOwnedParticleBacking::class,', $read);
        $this->assertStringContainsString('ActorOwnedParticleAccess::WRITE,', $read);
        $this->assertStringContainsString('public string $id,', $read);
        // BOTH shape slots, present and pointing at real emitted classes — the whole point of the ticket.
        // tutorial-dx 06: `data:` is omitted, because naming the annotated class there says what omitting it says.
        $this->assertStringNotContainsString('data: LyricData::class,', $read);
        $this->assertStringContainsString('input: LyricInputData::class,', $read);
        $this->assertStringContainsString('use Splicewire\Beam\Models\BeamParticle;', $read);

        // Shared storage keeps business names; no model-column map belongs in this default.
        $this->assertStringContainsString('class LyricInputData extends Data', $input);
        $this->assertStringNotContainsString('public function toModelAttributes(): array', $input);
    }

    /**
     * ADR-0230 Public Read is declared explicitly, never silent: a generated resource carries the `publicRead:`
     * slot set to `null` (authenticated-only) with its guidance, so an author decides guest access rather than
     * inheriting it, and `splicewire:beam:doctor` still finds the slot to count.
     */
    public function test_a_fresh_generated_default_stores_without_an_application_model(): void
    {
        \Illuminate\Support\Facades\Schema::create(\Splicewire\Beam\Facades\Beam::table('schemas'), function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_id')->unique();
            $table->string('schema_name');
            $table->integer('version');
            $table->string('fingerprint');
            $table->json('artifact');
            $table->timestamps();
        });
        \Illuminate\Support\Facades\Schema::create(\Splicewire\Beam\Facades\Beam::table('particles'), function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable();
            $table->string('schema_id')->nullable();
            $table->string('migration_status')->nullable();
            $table->string('head_version')->nullable();
            $table->string('source_tier')->default('local');
            $table->json('payload');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        $this->app['config']->set('data-schemas.registry_directory', $this->host.'/storage/schema-registry');
        $this->app->forgetInstance(\Schemastud\DataSchemas\Contracts\SchemaRegistry::class);
        $this->assertInstanceOf(\Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry::class, app(\Schemastud\DataSchemas\Contracts\SchemaRegistry::class));
        $actor = new \Illuminate\Foundation\Auth\User;
        $actor->id = 1;
        $this->actingAs($actor);
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'FreshStored'])->assertSuccessful();
        Particle::mount('fresh-storeds', 'fresh-storeds');
        $row = $this->postJson('/fresh-storeds', ['name' => 'generated'])->assertCreated()->json('data');
        $particle = \Splicewire\Beam\Models\BeamParticle::findOrFail($row['id']);
        $this->assertSame(['name' => 'generated'], $particle->payload);
        $this->assertSame('1', $particle->meta['owner']['id']);
        $this->assertFileDoesNotExist($this->host.'/app/Models/FreshStored.php');
        $this->getJson('/fresh-storeds/'.$row['id'])->assertOk();
        $this->patchJson('/fresh-storeds/'.$row['id'], [])->assertOk();
        $this->assertSame('generated', $particle->fresh()->payload['name']);
        $this->patchJson('/fresh-storeds/'.$row['id'], ['name' => null])->assertOk();
        $this->assertNull($particle->fresh()->payload['name']);
    }

    public function test_the_resource_generator_emits_an_explicit_null_public_read_slot(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Lyric'])->assertSuccessful();

        $read = $this->read('app/Data/LyricData.php');

        $this->assertMatchesRegularExpression('/#\[ParticleResource\((?:(?!\)\]).)*\n\s*publicRead: null,\n\)\]/s', $read);
        $this->assertStringContainsString('ADR-0230 Public Read', $read);
        $this->assertStringContainsString('`LyricData::class` when every field it', $read);
    }

    /**
     * `--summary` emits the provider and PRINTS the `summaryProvider:` line — it never edits the attribute,
     * for the same reason it never edits a route file. Without the flag no provider file exists: the resource
     * keeps beam's default (a scoped `total` count), which needs no class of its own.
     */
    public function test_the_resource_generator_emits_a_summary_provider_only_when_asked_and_prints_the_slot(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Lyric'])->assertSuccessful();
        $this->assertFileDoesNotExist($this->host.'/app/Particle/Summaries/LyricSummaryProvider.php');

        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Lyric', '--summary' => true, '--force' => true])
            ->expectsOutputToContain('summaryProvider: \\App\\Particle\\Summaries\\LyricSummaryProvider::class,')
            ->assertSuccessful();

        $provider = $this->read('app/Particle/Summaries/LyricSummaryProvider.php');

        $this->assertStringContainsString('namespace App\\Particle\\Summaries;', $provider);
        $this->assertStringContainsString('class LyricSummaryProvider implements ResourceSummaryProvider', $provider);
        $this->assertStringContainsString('public function summary(ResourceDefinition $resource): ?SummaryResponseData', $provider);
        // Counts through the shared scoped builder — the stub must not teach `Lyric::query()`.
        $this->assertStringContainsString('ScopedIndexQuery', $provider);
        $this->assertStringNotContainsString('Lyric::query()', $provider);
        // The read class is untouched: the generator prints the slot, it does not write it.
        $this->assertStringNotContainsString('summaryProvider:', $this->read('app/Data/LyricData.php'));

        // …and it PARSES. Every assertion above is a substring match, which a stub that lost a brace to a
        // placeholder edit would still satisfy — "the right words appeared" is not "the file compiles".
        $lint = new Process([PHP_BINARY, '-l', $this->host.'/app/Particle/Summaries/LyricSummaryProvider.php']);
        $lint->run();
        $this->assertSame(0, $lint->getExitCode(), $lint->getOutput().$lint->getErrorOutput());
    }

    // ── a qualified --model is a fully-qualified class, not a suffix ─────────────────────────────────

    /**
     * Laravel's own `GeneratorCommand::qualifyModel()` prefixes `App\Models\` onto anything that does not
     * already start with the root namespace — right for an app whose models all live under `App\`, wrong for
     * this estate, where the ordinary model is package-tier (`Splicewire\Tower\Models\…`). Measured at the
     * flagship (tenant-sync 16): `--model='Splicewire\Tower\Models\TenantSync'` emitted
     * `use App\Models\Splicewire\Tower\Models\TenantSync;`, an uncompilable scaffold from the generator
     * AGENTS.md tells every session to use.
     */
    public function test_the_resource_generator_keeps_a_namespaced_model_as_given(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', [
            'name' => 'TenantSync',
            '--model' => 'Splicewire\Tower\Models\TenantSync',
        ])->assertSuccessful();

        $read = $this->read('app/Data/TenantSyncData.php');

        $this->assertStringContainsString('use Splicewire\Tower\Models\TenantSync;', $read);
        $this->assertStringNotContainsString('App\Models\Splicewire', $read);
        $this->assertStringContainsString('backing: TenantSync::class,', $read);
    }

    public function test_a_leading_backslash_on_the_model_is_stripped_rather_than_prefixed(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', [
            'name' => 'TenantSync',
            '--model' => '\Splicewire\Tower\Models\TenantSync',
        ])->assertSuccessful();

        $read = $this->read('app/Data/TenantSyncData.php');

        $this->assertStringContainsString('use Splicewire\Tower\Models\TenantSync;', $read);
        $this->assertStringNotContainsString('use \\', $read);
    }

    public function test_a_bare_model_name_still_resolves_under_app_models(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', [
            'name' => 'Lyric',
            '--model' => 'Lyric',
        ])->assertSuccessful();

        $this->assertStringContainsString('use App\Models\Lyric;', $this->read('app/Data/LyricData.php'));
    }

    public function test_the_op_generator_keeps_a_namespaced_model_as_given(): void
    {
        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'Reconcile',
            '--resource' => 'tenant-syncs',
            '--model' => 'Splicewire\Tower\Models\TenantSync',
        ])->assertSuccessful();

        $op = $this->read('app/Particle/Operations/Reconcile.php');

        $this->assertStringContainsString('use Splicewire\Tower\Models\TenantSync;', $op);
        $this->assertStringNotContainsString('App\Models\Splicewire', $op);
        $this->assertStringContainsString('public static function handle(TenantSync $model', $op);
    }

    /**
     * The docblock's mount line and the command's own closing INFO line named two different verbs —
     * `Route::particleResource(...)` in the stub, `Particle::mount(...)` in the report — and the `Route::`
     * macros were deleted by api-surface-coherence 93. One verb, the live one, in both places.
     */
    public function test_the_emitted_mount_hint_names_the_live_verb_not_the_deleted_route_macro(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Lyric'])->assertSuccessful();
        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'Reorder',
            '--resource' => 'lyrics',
            '--model' => 'Lyric',
        ])->assertSuccessful();

        $read = $this->read('app/Data/LyricData.php');
        $op = $this->read('app/Particle/Operations/Reorder.php');

        $this->assertStringContainsString("Particle::mount('lyrics', 'lyrics');", $read);
        $this->assertStringNotContainsString('Route::particleResource', $read);
        $this->assertStringContainsString("Particle::ops('lyrics', 'lyrics', [Reorder::class]);", $op);
        $this->assertStringNotContainsString('Route::particleOps', $op);
    }

    public function test_the_op_generator_emits_the_input_output_and_ability_slots_plus_both_data_classes(): void
    {
        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'RegenerateOp',
            '--resource' => 'lyrics',
            '--model' => 'Lyric',
            '--kind' => 'write',
        ])->assertSuccessful();

        $op = $this->read('app/Particle/Operations/RegenerateOp.php');

        $this->assertStringContainsString("resource: 'lyrics',", $op);
        $this->assertStringContainsString("name: 'regenerate',", $op);
        $this->assertStringContainsString('kind: OperationKind::Write,', $op);
        $this->assertStringContainsString('input: RegenerateInputData::class,', $op);
        $this->assertStringContainsString('output: RegenerateOutputData::class,', $op);
        // The ability slot gets a kind-derived default rather than a null — an emitted `ability: null` teaches
        // the opposite of what a deny-default slot is for.
        $this->assertStringContainsString("ability: 'update',", $op);
        // `affordance:` is emitted explicitly (particle-operation-surface 21): leaving it off is the
        // undeclared state `particle.operation-affordance` counts, so the default is a written `false`.
        $this->assertStringContainsString('affordance: false,', $op);

        // Discovery THROWS on an op class with no `handle()`, so the generator emitting one is not cosmetic.
        $this->assertStringContainsString('public static function handle(', $op);

        $this->assertFileExists($this->host.'/app/Data/RegenerateInputData.php');
        $this->assertFileExists($this->host.'/app/Data/RegenerateOutputData.php');
    }

    public function test_the_op_generator_draws_a_frame_action_on_request_and_refuses_an_unknown_affordance(): void
    {
        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'ReloadOp',
            '--resource' => 'lyrics',
            '--model' => 'Lyric',
            '--affordance' => 'action',
        ])->assertSuccessful();

        $this->assertStringContainsString("affordance: 'action',", $this->read('app/Particle/Operations/ReloadOp.php'));

        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'ButtonOp',
            '--resource' => 'lyrics',
            '--model' => 'Lyric',
            '--affordance' => 'button',
        ])->assertFailed();
    }

    // ── kind-correctness: a wrong output shape FATALS at registration ───────────────────────────────

    public function test_a_stream_op_emits_an_event_name_map_and_the_four_arg_emitter_signature(): void
    {
        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'WatchOp',
            '--resource' => 'lyrics',
            '--model' => 'Lyric',
            '--kind' => 'stream',
        ])->assertSuccessful();

        $op = $this->read('app/Particle/Operations/WatchOp.php');

        $this->assertStringContainsString('kind: OperationKind::Stream,', $op);
        // A stream's output is inherently event-keyed; ParticleOperation rejects a bare class-string here.
        $this->assertStringContainsString("output: ['watch_status' => [WatchOutputData::class]],", $op);
        $this->assertStringContainsString('Emitter $emit', $op);
    }

    public function test_a_task_op_emits_a_single_class_string_output_plus_the_respond_projector(): void
    {
        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'RebuildOp',
            '--resource' => 'lyrics',
            '--model' => 'Lyric',
            '--kind' => 'task',
        ])->assertSuccessful();

        $op = $this->read('app/Particle/Operations/RebuildOp.php');

        $this->assertStringContainsString('kind: OperationKind::Task,', $op);
        $this->assertStringContainsString('output: RebuildOutputData::class,', $op);
        $this->assertStringNotContainsString('=>', substr($op, strpos($op, 'output:'), 60));
        // A task's handle() returns the JOB, so without the projector the `output:` declaration is untrue.
        $this->assertStringContainsString(': ShouldQueue', $op);
        $this->assertStringContainsString('public static function respond(', $op);
    }

    public function test_every_kind_registers_rather_than_being_rejected_by_the_output_kind_guard(): void
    {
        // ParticleOperation's constructor rejects a map on a non-stream kind AND a bare class-string on a
        // Stream, so a stub with the wrong output shape for its kind fatals the moment discovery reads it.
        // Registering all four is the direct test that the generator never emits that combination.
        foreach (OperationKind::cases() as $kind) {
            $class = 'Gen'.Str::studly($kind->value).'Op';

            $this->artisan('splicewire:beam:make:particle-op', [
                'name' => $class,
                '--resource' => 'lyrics',
                '--model' => 'Lyric',
                '--kind' => $kind->value,
            ])->assertSuccessful();

            $operation = $this->registerOp($class, 'gen-'.$kind->value);

            $this->assertSame($kind, $operation->kind);
            $kind === OperationKind::Stream
                ? $this->assertIsArray($operation->output)
                : $this->assertIsString($operation->output);
        }
    }

    // ── the acceptance criterion: a generated surface is conformance-clean ──────────────────────────

    public function test_a_freshly_generated_resource_mounted_on_a_route_produces_no_undeclared_surface_finding(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Lyric'])->assertSuccessful();

        $this->load('App\Data\LyricInputData', 'app/Data/LyricInputData.php');
        $this->load('App\Data\LyricData', 'app/Data/LyricData.php');

        $this->app->make(AttributedParticleDiscovery::class)->discover(classes: ['App\Data\LyricData']);
        Route::prefix('api')->group(fn () => Particle::mount('lyrics', 'lyrics'));

        $this->assertSame('lyrics', $this->app->make(ParticleResourceRegistry::class)->get('lyrics')->key);
        $this->assertNoFindingsFor('api/lyrics');
    }

    public function test_a_freshly_generated_operation_of_every_kind_produces_no_undeclared_surface_finding(): void
    {
        foreach (OperationKind::cases() as $kind) {
            $class = 'Clean'.Str::studly($kind->value).'Op';

            $this->artisan('splicewire:beam:make:particle-op', [
                'name' => $class,
                '--resource' => 'lyrics',
                '--op' => $kind->value.'-it',
                '--model' => 'Lyric',
                '--kind' => $kind->value,
            ])->assertSuccessful();

            $this->registerOp($class, $kind->value.'-it');
            Route::prefix('api')->group(fn () => Particle::ops('lyrics', 'lyrics', $kind->value.'-it'));

            $this->assertNoFindingsFor('api/lyrics/{id}/'.$kind->value.'-it');
        }
    }

    // ── the generator checks its own output (make-surface 09) ───────────────────────────────────────

    public function test_a_generator_whose_stub_is_forced_to_emit_an_undeclared_shape_exits_1(): void
    {
        $this->publishOpStubWithoutItsOutputSlot();

        // A class name no other test generates: an earlier test's run may already have loaded a same-named,
        // declared class, and a stale one would let this pass by accident.
        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'GhostOp',
            '--resource' => 'lyrics',
            '--model' => 'Lyric',
            '--kind' => 'write',
        ])
            ->expectsOutputToContain(UndeclaredSurfaceAudit::CHECK)
            ->assertExitCode(1);

        // The files are still written: the check reports, it does not roll back the author's tree.
        $this->assertFileExists($this->host.'/app/Particle/Operations/GhostOp.php');
    }

    public function test_no_verify_skips_the_check_and_exits_0_on_the_same_stub(): void
    {
        $this->publishOpStubWithoutItsOutputSlot();

        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'PhantomOp',
            '--resource' => 'lyrics',
            '--model' => 'Lyric',
            '--kind' => 'write',
            '--no-verify' => true,
        ])->assertExitCode(0);
    }

    public function test_force_over_an_already_loaded_declared_op_checks_the_bytes_just_written(): void
    {
        // Codex review 09 finding 1. The first run loads RebindOp into this process, exactly as a host's boot
        // discovery or an earlier Artisan call would. The --force rerun then rewrites the SAME class through an
        // override that drops its output; the check must judge those bytes, not the definition already loaded.
        $args = ['name' => 'RebindOp', '--resource' => 'lyrics', '--model' => 'Lyric', '--kind' => 'write'];

        $this->artisan('splicewire:beam:make:particle-op', $args)->assertExitCode(0);
        $this->assertTrue(class_exists('App\Particle\Operations\RebindOp', false), 'The first run did not load the op.');

        $this->publishOpStubWithoutItsOutputSlot();

        $this->artisan('splicewire:beam:make:particle-op', $args + ['--force' => true])
            ->expectsOutputToContain(UndeclaredSurfaceAudit::CHECK)
            ->assertExitCode(1);
    }

    public function test_an_invalid_run_does_not_fail_a_later_valid_run_in_the_same_kernel(): void
    {
        // Codex review 09 finding 2. Laravel keeps one command instance per kernel, so state a run leaves behind
        // (its emitted files, the routes its check mounted) must not decide the next run.
        Route::get('unrelated-host-route', fn () => 'kept')->name('unrelated.host');

        $this->publishOpStubWithoutItsOutputSlot();
        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'BrokenOp', '--resource' => 'lyrics', '--model' => 'Lyric', '--kind' => 'write',
        ])->assertExitCode(1);

        File::delete($this->host.'/stubs/particle-op.stub');
        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'ValidOp', '--resource' => 'lyrics', '--model' => 'Lyric', '--kind' => 'write',
        ])->assertExitCode(0);

        // The check adds its own routes and removes nothing of the host's. (A route named after it is added is not
        // in the collection's name index until the lookups are refreshed.)
        Route::getRoutes()->refreshNameLookups();
        $this->assertSame('unrelated-host-route', Route::getRoutes()->getByName('unrelated.host')?->uri());
    }

    public function test_the_check_reaches_the_surface_it_verifies(): void
    {
        // Guards the clean results above from passing because nothing was mounted: a check that reports
        // "no findings" by not looking is the defect class this suite exists to catch.
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Reached', '--model' => 'Lyric'])->assertSuccessful();
        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'ReachedOp',
            '--resource' => 'reacheds',
            '--model' => 'Lyric',
            '--kind' => 'read',
        ])->assertSuccessful();

        // Each run mounts under its own segment: <VERIFY_PREFIX>/<run>/reacheds…
        $uris = array_map(fn ($route) => $route->uri(), Route::getRoutes()->getRoutes());
        $run = '#^'.preg_quote(ParticleGeneratorCommand::VERIFY_PREFIX, '#').'/[0-9a-f]{8}/reacheds';

        $this->assertNotEmpty(preg_grep($run.'$#', $uris), 'The resource check did not mount the resource it verified.');
        $this->assertNotEmpty(preg_grep($run.'/\{id\}/#', $uris), 'The op check did not mount the op it verified.');
    }

    public function test_every_generator_offers_no_verify(): void
    {
        $commands = $this->app->make(Kernel::class)->all();

        foreach ([
            'splicewire:beam:make:particle-resource',
            'splicewire:beam:make:particle-op',
            'splicewire:beam:make:particle-relative',
            'splicewire:beam:make:particle-backing',
            'splicewire:beam:make:workflow',
            'splicewire:beam:make:page',
        ] as $name) {
            $this->assertTrue($commands[$name]->getDefinition()->hasOption('no-verify'), "{$name} has no --no-verify");
        }
    }

    public function test_the_generated_tree_introduces_no_uncited_central_pin(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Lyric'])->assertSuccessful();
        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'RegenerateOp',
            '--resource' => 'lyrics',
            '--model' => 'Lyric',
            '--kind' => 'task',
        ])->assertSuccessful();

        $this->assertSame([], (new CentralPinJustificationAudit([$this->host.'/app']))->pins());
    }

    public function test_the_central_pin_audit_actually_reaches_the_generated_tree(): void
    {
        // Guards the assertion above from passing by EXCLUSION rather than by conformance: the audit drops
        // whole path fragments, so a temp directory that happened to match one would make a clean result
        // meaningless. Plant an uncited pin in the same tree and require that it IS reported.
        File::put($this->host.'/app/Models/Pinned.php', <<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Database\Eloquent\Model;

        class Pinned extends Model
        {
            protected $connection = 'central';
        }
        PHP);

        $this->assertCount(1, (new CentralPinJustificationAudit([$this->host.'/app']))->pins());
    }

    public function test_the_generated_tree_is_already_formatter_clean(): void
    {
        // Emitted code that a host's first `pint` immediately rewrites teaches the author that the generator's
        // output is approximate. It also puts unrelated churn in the commit that introduces the surface, which
        // is where the deviations this ticket is about get laundered in.
        $pint = dirname(__DIR__, 2).'/vendor/bin/pint';

        if (! is_file($pint)) {
            $this->markTestSkipped('pint is not installed in this checkout.');
        }

        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Lyric'])->assertSuccessful();

        foreach (OperationKind::cases() as $kind) {
            $this->artisan('splicewire:beam:make:particle-op', [
                'name' => 'Fmt'.Str::studly($kind->value).'Op',
                '--resource' => 'lyrics',
                '--model' => 'Lyric',
                '--kind' => $kind->value,
            ])->assertSuccessful();
        }

        // Only the GENERATED trees — app/Models holds this test's own hand-written fixture.
        $process = Process::fromShellCommandline(sprintf(
            'XDEBUG_MODE=off %s --test %s %s 2>&1',
            escapeshellarg($pint),
            escapeshellarg($this->host.'/app/Data'),
            escapeshellarg($this->host.'/app/Particle'),
        ));
        $process->run();

        $this->assertTrue($process->isSuccessful(), 'Generated code is not pint-clean: '.$process->getOutput());
    }

    // ── discoverability ────────────────────────────────────────────────────────────────────────────

    public function test_both_generators_appear_in_the_namespaced_command_listing(): void
    {
        // `beam:sitemap:generate` and `beam:pnpm-overrides` used to sit outside `splicewire:` (fixed);
        // these two generators are not allowed to reintroduce that drift.
        $registered = array_keys($this->app->make(Kernel::class)->all());

        $this->assertContains('splicewire:beam:make:particle-resource', $registered);
        $this->assertContains('splicewire:beam:make:particle-op', $registered);

        foreach (['make:particle-resource', 'make:particle-op'] as $bare) {
            $this->assertNotContains($bare, $registered);
            $this->assertNotContains('beam:'.$bare, $registered);
        }
    }

    // ── re-running is non-destructive ───────────────────────────────────────────────────────────────

    public function test_a_second_run_keeps_a_companion_data_class_the_author_has_since_filled_in(): void
    {
        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'RegenerateOp',
            '--resource' => 'lyrics',
            '--model' => 'Lyric',
            '--kind' => 'write',
        ])->assertSuccessful();

        File::put($this->host.'/app/Data/RegenerateInputData.php', '<?php // hand-edited');

        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'RegenerateOp',
            '--resource' => 'lyrics',
            '--model' => 'Lyric',
            '--kind' => 'write',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame('<?php // hand-edited', $this->read('app/Data/RegenerateInputData.php'));
    }

    public function test_an_unknown_kind_fails_rather_than_emitting_a_surface_with_no_valid_kind(): void
    {
        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'BogusOp',
            '--resource' => 'lyrics',
            '--model' => 'Lyric',
            '--kind' => 'sideways',
        ])->assertFailed();

        $this->assertFileDoesNotExist($this->host.'/app/Particle/Operations/BogusOp.php');
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────────────

    // ── tutorial-dx 06: scaffold by intent ──────────────────────────────────────────────────────────────
    //
    // Before: the tutorial's first scaffold wrote a write DTO the next step deleted, ch-02's op wrote an output class
    // the next step deleted, a resource named itself in `data:`, and an op with `--model` omitted wrote an unchecked
    // `use App\Models\Plan;`. After: the reader states the intent with a flag, every decision slot is written out, and
    // the printed mount line is the one 01's bare-mount defaults make true for the declaration.

    public function test_a_resource_states_read_only_as_a_decision_and_omits_the_self_naming_data_slot(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Verse', '--model' => 'Lyric'])
            ->expectsOutputToContain("Particle::mount('verses', 'verses'); // index, show, store, update, destroy")
            ->assertSuccessful();

        $read = $this->read('app/Data/VerseData.php');

        $this->assertStringNotContainsString('data: VerseData::class', $read, 'an omitted data: already means the annotated class');
        $this->assertStringContainsString('readOnly: false,', $read, 'a decision, written out');
        $this->assertStringContainsString('input: VerseInputData::class,', $read);
    }

    public function test_read_only_declares_no_write_shape_writes_no_write_dto_and_prints_the_read_verbs(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Chorus', '--model' => 'Lyric', '--key' => 'choruses', '--read-only' => true])
            ->expectsOutputToContain("Particle::mount('choruses', 'choruses'); // index, show")
            ->assertSuccessful();

        $read = $this->read('app/Data/ChorusData.php');

        $this->assertStringContainsString('readOnly: true,', $read);
        $this->assertStringContainsString('input: false,', $read, 'no write shape is a decision, not an omission');
        $this->assertFileDoesNotExist($this->host.'/app/Data/ChorusInputData.php', 'nothing for the next step to delete');
        $this->assertStringContainsString("Particle::mount('choruses', 'choruses');", $read);
    }

    public function test_the_printed_bare_mount_of_a_read_only_resource_mounts_only_its_read_verbs(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Bridge', '--model' => 'Lyric', '--key' => 'bridges', '--read-only' => true])
            ->assertSuccessful();

        $this->load('App\Data\BridgeData', 'app/Data/BridgeData.php');
        $this->app->make(AttributedParticleDiscovery::class)->discover(classes: ['App\Data\BridgeData']);
        Route::prefix('api')->group(fn () => Particle::mount('bridges', 'bridges'));

        $verbs = [];
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/bridges')) {
                $verbs[] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
            }
        }
        $this->assertNotContains('POST api/bridges', $verbs, '01: a bare mount derives its verbs from the declaration');
        $this->assertContains('GET api/bridges', $verbs);
        $this->assertNoFindingsFor('api/bridges');
    }

    public function test_each_storage_mode_passes_the_generators_own_verification(): void
    {
        foreach (['actor' => 'ActorOwnedParticleBacking', 'workspace' => 'WorkspaceSharedParticleBacking', 'central' => 'CentralParticleBacking'] as $mode => $backing) {
            $name = ucfirst($mode).'Storage';
            $options = ['name' => $name, '--ownership' => $mode];
            if ($mode !== 'actor') {
                $options['--write-ability'] = 'storage.manage';
            }
            $this->artisan('splicewire:beam:make:particle-resource', $options)->assertSuccessful();
            $read = $this->read('app/Data/'.$name.'Data.php');
            $this->assertStringContainsString($backing.'::class', $read);
            if ($mode !== 'actor') {
                $this->assertStringContainsString("policy: 'storage.manage'", $read);
                $this->assertStringContainsString('SharedParticleAccess::class', $read);
            }
        }
    }

    public function test_shared_storage_refuses_without_a_named_ability_before_emitting_files(): void
    {
        foreach (['workspace', 'central'] as $mode) {
            $name = ucfirst($mode).'WithoutAbility';
            $this->artisan('splicewire:beam:make:particle-resource', ['name' => $name, '--ownership' => $mode])
                ->expectsOutputToContain('requires --write-ability')->assertFailed();
            $this->assertFileDoesNotExist($this->host.'/app/Data/'.$name.'Data.php');
        }
    }

    public function test_shared_verification_refuses_a_host_stub_that_omits_the_ability(): void
    {
        File::ensureDirectoryExists($this->host.'/stubs');
        $stub = (string) file_get_contents(__DIR__.'/../../stubs/particle-resource.stub');
        File::put($this->host.'/stubs/particle-resource.stub', str_replace('{{ authorizationLine }}', '', $stub));
        $this->artisan('splicewire:beam:make:particle-resource', [
            'name' => 'BrokenShared', '--ownership' => 'workspace', '--write-ability' => 'storage.manage',
        ])->assertFailed();
    }

    public function test_owned_default_refuses_public_read_without_an_explicit_backing_choice(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'PublicOwned', '--public' => true])
            ->expectsOutputToContain('Actor-owned storage has no Public Read')->assertFailed();

        $this->assertFileDoesNotExist($this->host.'/app/Data/PublicOwnedData.php');
    }

    public function test_public_names_the_read_class_as_the_public_read(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Riff', '--model' => 'Lyric', '--public' => true])
            ->assertSuccessful();

        $this->assertStringContainsString('publicRead: RiffData::class,', $this->read('app/Data/RiffData.php'));
    }

    public function test_no_subject_declares_no_subject_imports_no_model_and_takes_mixed_subject(): void
    {
        $this->artisan('splicewire:beam:make:particle-op', ['name' => 'PurchasePlan', '--resource' => 'plans', '--op' => 'purchase', '--no-subject' => true])
            ->assertSuccessful();

        $op = $this->read('app/Particle/Operations/PurchasePlan.php');

        $this->assertStringContainsString('subject: NoSubject::class,', $op);
        $this->assertStringContainsString('abilityModel: false,', $op);
        $this->assertStringContainsString('use Splicewire\Beam\Particle\Subject\NoSubject;', $op);
        $this->assertStringNotContainsString('use App\Models\\', $op, 'no unchecked model import');
        $this->assertStringContainsString('public static function handle(mixed $subject, Request $request, mixed $actor)', $op);
    }

    // orch-peer's REVISE (2026-11-08): a subject-free op's flat {uri}/{op} loses to an earlier {uri}/{id}.

    public function test_a_no_subject_op_prints_and_documents_its_placement_above_the_id_routes(): void
    {
        $this->artisan('splicewire:beam:make:particle-op', ['name' => 'SearchPlans', '--kind' => 'read', '--resource' => 'plans', '--op' => 'search', '--no-subject' => true])
            ->expectsOutputToContain("Mount this line ABOVE Particle::mount('plans', …)")
            ->assertSuccessful();

        $docblock = str_replace("\n * ", ' ', $this->read('app/Particle/Operations/SearchPlans.php'));
        $this->assertStringContainsString("Mount this line ABOVE Particle::mount('plans', …)", $docblock);
        $this->assertStringContainsString('flat plans/search', $docblock);
    }

    public function test_co_mounted_below_the_resource_a_no_subject_op_is_swallowed_by_the_id_route(): void
    {
        [$resource, $op] = $this->tunesAndSearch();
        // Mounted GET, as a search usually is (the doctrine's measured case): a read op's default POST has no {id} rival.
        Route::prefix('api')->group(function () use ($op): void {
            Particle::mount('tunes', 'tunes');
            Particle::ops('tunes', 'tunes', [$op], ['method' => 'get']);
        });

        $this->assertSame('api/tunes/{id}', $this->routeFor('GET', '/api/tunes/search'), 'the wrong order: show answers with id = "search"');
    }

    public function test_co_mounted_in_the_printed_order_a_no_subject_op_answers_its_own_url(): void
    {
        [$resource, $op] = $this->tunesAndSearch();
        Route::prefix('api')->group(function () use ($op): void {
            Particle::ops('tunes', 'tunes', [$op], ['method' => 'get']);
            Particle::mount('tunes', 'tunes');
        });

        $this->assertSame('api/tunes/search', $this->routeFor('GET', '/api/tunes/search'));
        $this->assertSame('api/tunes/{id}', $this->routeFor('GET', '/api/tunes/7'), 'the resource\'s own show still answers');
    }

    /** @return array{0: class-string, 1: class-string} a generated resource and a subject-free read op on the same uri, discovered */
    private function tunesAndSearch(): array
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Tune', '--model' => 'Lyric', '--key' => 'tunes'])->assertSuccessful();
        $this->artisan('splicewire:beam:make:particle-op', ['name' => 'SearchTunes', '--kind' => 'read', '--resource' => 'tunes', '--op' => 'search', '--no-subject' => true])->assertSuccessful();

        $this->load('App\Data\TuneInputData', 'app/Data/TuneInputData.php');
        $this->load('App\Data\TuneData', 'app/Data/TuneData.php');
        $this->load('App\Data\SearchTunesInputData', 'app/Data/SearchTunesInputData.php');
        $this->load('App\Data\SearchTunesOutputData', 'app/Data/SearchTunesOutputData.php');
        $this->load('App\Particle\Operations\SearchTunes', 'app/Particle/Operations/SearchTunes.php');
        $this->app->make(AttributedParticleDiscovery::class)->discover(classes: ['App\Data\TuneData', 'App\Particle\Operations\SearchTunes']);

        return ['App\Data\TuneData', 'App\Particle\Operations\SearchTunes'];
    }

    private function routeFor(string $method, string $uri): string
    {
        return Route::getRoutes()->match(\Illuminate\Http\Request::create($uri, $method))->uri();
    }

    public function test_an_omitted_model_whose_derived_class_does_not_exist_is_refused_naming_both_ways_out(): void
    {
        $this->artisan('splicewire:beam:make:particle-op', ['name' => 'Refund', '--resource' => 'plans'])
            ->expectsOutputToContain('Name the model with --model, or declare the operation subject-free with --no-subject.')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->host.'/app/Particle/Operations/Refund.php');
    }

    public function test_output_names_an_existing_class_and_writes_no_output_class(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Stanza', '--model' => 'Lyric'])->assertSuccessful();
        $this->artisan('splicewire:beam:make:particle-op', [
            'name' => 'ResolveStanza', '--kind' => 'read', '--resource' => 'stanzas', '--model' => 'Lyric', '--output' => 'App\Data\StanzaData',
        ])->assertSuccessful();

        $op = $this->read('app/Particle/Operations/ResolveStanza.php');

        $this->assertStringContainsString('output: StanzaData::class,', $op);
        $this->assertStringContainsString('use App\Data\StanzaData;', $op);
        $this->assertFileDoesNotExist($this->host.'/app/Data/ResolveStanzaOutputData.php', 'nothing for the next step to delete');
        $this->assertFileExists($this->host.'/app/Data/ResolveStanzaInputData.php');
    }

    public function test_output_naming_a_class_that_does_not_exist_or_on_a_stream_or_task_is_refused(): void
    {
        $this->artisan('splicewire:beam:make:particle-op', ['name' => 'Ghost', '--resource' => 'lyrics', '--model' => 'Lyric', '--output' => 'App\Data\NopeData'])
            ->assertFailed();
        $this->artisan('splicewire:beam:make:particle-op', ['name' => 'Flow', '--kind' => 'stream', '--resource' => 'lyrics', '--model' => 'Lyric', '--output' => 'App\Models\Lyric'])
            ->assertFailed();
        $this->artisan('splicewire:beam:make:particle-op', ['name' => 'Batch', '--kind' => 'task', '--resource' => 'lyrics', '--no-subject' => true])
            ->assertFailed();

        foreach (['Ghost', 'Flow', 'Batch'] as $name) {
            $this->assertFileDoesNotExist($this->host."/app/Particle/Operations/{$name}.php");
        }
    }

    private function read(string $relative): string
    {
        $path = $this->host.'/'.$relative;

        $this->assertFileExists($path);

        return (string) File::get($path);
    }

    /**
     * A host override of the read/write op stub (the `resolveStubPath` convention) that declares no output:
     * `output:` is nulled, so the mounted op is exactly what {@see UndeclaredSurfaceAudit} counts. (A resource
     * cannot be forced this way: discovery defaults its `data:` to the annotated Data class itself.)
     */
    private function publishOpStubWithoutItsOutputSlot(): void
    {
        $stub = File::get(dirname(__DIR__, 2).'/stubs/particle-op.stub');
        $broken = str_replace('    output: {{ outputClass }}::class,', '    output: null,', $stub);

        $this->assertNotSame($stub, $broken, 'The op stub no longer carries the output: slot this test removes.');

        File::ensureDirectoryExists($this->host.'/stubs');
        File::put($this->host.'/stubs/particle-op.stub', $broken);
    }

    /**
     * Require a generated class unless it is already loaded. Every generator run now verifies its output, which
     * loads the emitted classes into this process; each test's host is a fresh directory, so a plain
     * `require_once` (keyed on the path) of a class an earlier test's run already loaded would redeclare it.
     */
    private function load(string $class, string $relative): void
    {
        if (! class_exists($class, false)) {
            require_once $this->host.'/'.$relative;
        }
    }

    /** Load a generated op through real attribute discovery — the path that enforces `handle()` and the kind guard. */
    private function registerOp(string $class, string $opName): ParticleOperation
    {
        $base = Str::replaceLast('Op', '', $class);

        $this->load('App\Data\\'.$base.'InputData', 'app/Data/'.$base.'InputData.php');
        $this->load('App\Data\\'.$base.'OutputData', 'app/Data/'.$base.'OutputData.php');
        $this->load('App\Particle\Operations\\'.$class, 'app/Particle/Operations/'.$class.'.php');

        $this->app->make(AttributedParticleDiscovery::class)
            ->discover(classes: ['App\Particle\Operations\\'.$class]);

        $operation = $this->app->make(ParticleOperationRegistry::class)->find('lyrics', $opName);

        $this->assertNotNull($operation, "Discovery did not register the generated op [{$class}].");

        return $operation;
    }

    private function assertNoFindingsFor(string $uriPrefix): void
    {
        $audit = new UndeclaredSurfaceAudit(
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
        );

        $offending = array_values(array_filter(
            $audit->undeclared(),
            fn (array $row) => str_starts_with($row['uri'], $uriPrefix),
        ));

        $this->assertSame([], $offending, sprintf(
            'A freshly generated surface tripped the undeclared-surface audit at [%s] — the generator is wrong.',
            $uriPrefix,
        ));
    }
}
