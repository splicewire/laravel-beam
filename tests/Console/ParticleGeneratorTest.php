<?php

namespace Splicewire\Beam\Tests\Console;

use App\Models\Lyric;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
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

    public function test_the_resource_generator_emits_a_read_data_class_and_the_write_dto_its_input_slot_names(): void
    {
        $this->artisan('splicewire:beam:make:particle-resource', ['name' => 'Lyric'])->assertSuccessful();

        // The `Data` suffix is forced: the attribute belongs on a Data class, and the suffix is also what makes
        // the read class / model short-name collision structurally unreachable.
        $read = $this->read('app/Data/LyricData.php');
        $input = $this->read('app/Data/LyricInputData.php');

        $this->assertStringContainsString("key: 'lyrics',", $read);
        $this->assertStringContainsString('model: Lyric::class,', $read);
        // BOTH shape slots, present and pointing at real emitted classes — the whole point of the ticket.
        $this->assertStringContainsString('data: LyricData::class,', $read);
        $this->assertStringContainsString('input: LyricInputData::class,', $read);
        $this->assertStringContainsString('use App\Models\Lyric;', $read);

        // The write DTO carries the `toModelAttributes()` convention the controller looks for; without it the
        // surface silently falls back to a snake-map of whatever arrived, which is a shape nobody declared.
        $this->assertStringContainsString('class LyricInputData extends Data', $input);
        $this->assertStringContainsString('public function toModelAttributes(): array', $input);
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

        // Discovery THROWS on an op class with no `handle()`, so the generator emitting one is not cosmetic.
        $this->assertStringContainsString('public static function handle(', $op);

        $this->assertFileExists($this->host.'/app/Data/RegenerateInputData.php');
        $this->assertFileExists($this->host.'/app/Data/RegenerateOutputData.php');
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

        require_once $this->host.'/app/Data/LyricInputData.php';
        require_once $this->host.'/app/Data/LyricData.php';

        $this->app->make(AttributedParticleDiscovery::class)->discover(classes: ['App\Data\LyricData']);
        Route::prefix('api')->group(fn () => Route::particleResource('lyrics', 'lyrics'));

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
            Route::prefix('api')->group(fn () => Route::particleOp('lyrics', 'lyrics', $kind->value.'-it'));

            $this->assertNoFindingsFor('api/lyrics/{id}/op/'.$kind->value.'-it');
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
        // `beam:sitemap:generate` and `beam:pnpm-overrides` sit outside `splicewire:` and are a recorded
        // defect; these two are not allowed to add to it.
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

    private function read(string $relative): string
    {
        $path = $this->host.'/'.$relative;

        $this->assertFileExists($path);

        return (string) File::get($path);
    }

    /** Load a generated op through real attribute discovery — the path that enforces `handle()` and the kind guard. */
    private function registerOp(string $class, string $opName): ParticleOperation
    {
        $base = Str::replaceLast('Op', '', $class);

        require_once $this->host.'/app/Data/'.$base.'InputData.php';
        require_once $this->host.'/app/Data/'.$base.'OutputData.php';
        require_once $this->host.'/app/Particle/Operations/'.$class.'.php';

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
