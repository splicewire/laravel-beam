<?php

namespace Splicewire\Beam\Tests\Install;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Splicewire\Beam\Console\BeamInstallCommand;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Install\InstallStep;
use Splicewire\Beam\Scribe\Strategies\GroupStrategy;
use Splicewire\Beam\Scribe\Strategies\ModelsResponseEnvelope;
use Splicewire\Beam\Scribe\Strategies\ParticleRequestStrategy;
use Splicewire\Beam\Scribe\Strategies\ParticleResponseStrategy;
use Splicewire\Beam\Scribe\Strategies\ParticleTitleStrategy;
use Splicewire\Beam\Scribe\Strategies\ReturnsResponseStrategy;
use Splicewire\Beam\Scribe\Strategies\RouteTitleStrategy;
use Splicewire\Beam\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The beam-install orchestrator + self-registration manifest (beam-write-pipeline ticket 08): one
 * command sets up the whole stack, and a package joins by registering its OWN step — beam-core never
 * names a consumer, and steps run core-first.
 */
class BeamInstallTest extends TestCase
{
    /**
     * The real `vendor:publish` these tests drive writes config + timestamped migrations into the
     * SHARED testbench skeleton on disk, where they persist past the process and shadow the package
     * defaults for every later test (and every later run — each run stamps fresh duplicate
     * migrations, eventually breaking migrate with "table already exists"). Sweep the published
     * artifacts back out after each test so the skeleton stays pristine.
     */
    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('config/beam'));

        foreach (['', '/shared', '/tenant'] as $sub) {
            $dir = base_path('database/migrations'.$sub);
            $leaked = [
                ...glob($dir.'/[0-9]*_create_beam_*.php') ?: [],
                ...glob($dir.'/[0-9]*_create_media_table.php') ?: [],
            ];
            foreach ($leaked as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_the_manifest_orders_steps_core_first(): void
    {
        $manifest = new BeamInstallManifest;
        $manifest->register('a-consumer', ['a-config'], order: 100);
        $manifest->register('z-core', ['z-config'], migrates: true, order: 0);
        $manifest->register('m-consumer', ['m-config'], order: 100);

        $order = array_map(fn ($s) => $s->package, $manifest->steps());

        // Core (order 0) first; ties keep registration order (usort is stable since PHP 8).
        $this->assertSame(['z-core', 'a-consumer', 'm-consumer'], $order);
        $this->assertTrue($manifest->migrates());
    }

    public function test_registration_is_idempotent_per_package(): void
    {
        $manifest = new BeamInstallManifest;
        $manifest->register('dup', ['old-tag']);
        $manifest->register('dup', ['new-tag']);

        $steps = $manifest->steps();
        $this->assertCount(1, $steps);
        $this->assertSame(['new-tag'], $steps[0]->publishTags);
    }

    public function test_beam_core_self_registers_its_own_step_core_first(): void
    {
        // beam-core's provider pushed its OWN step into the container-singleton manifest at boot.
        $steps = $this->app->make(BeamInstallManifest::class)->steps();

        $this->assertNotEmpty($steps);
        $core = $steps[0];
        $this->assertSame(0, $core->order, 'beam-core registers core-first (order 0)');
        $this->assertStringContainsString('laravel-beam', $core->package);
        // package-tools strips the `laravel-` prefix (Package::shortName() → `beam`), so the real
        // vendor:publish group names are `beam-config` / `beam-migrations` (NOT `laravel-beam-*`).
        $this->assertContains('beam-config', $core->publishTags);
        $this->assertContains('beam-migrations', $core->publishTags);
        // ADR-0211 §7: the scribe stub is an INSTALL step, unlike beam-stubs — a fresh starter must boot
        // with a spec, and it cannot generate one worth reading from Scribe's stock config.
        $this->assertContains('beam-scribe', $core->publishTags);
        $this->assertTrue($core->migrates);
    }

    /**
     * ADR-0211 §7: publishing the stub puts beam's emitter-only Scribe config in the host — the pair that
     * keeps Scribe from growing a second docs UI, and the `api/*` match rules that are the exposure
     * boundary for the (public by default) artifact route.
     */
    public function test_the_scribe_stub_publishes_beams_emitter_only_defaults(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'beam-scribe'])->assertExitCode(0);

        $published = config_path('scribe.php');
        $this->assertFileExists($published);

        $config = require $published;

        $this->assertSame('laravel', $config['type']);
        $this->assertFalse($config['laravel']['add_routes']);
        $this->assertTrue($config['openapi']['enabled']);
        $this->assertFalse($config['postman']['enabled']);
        $this->assertSame(['api/*'], $config['routes'][0]['match']['prefixes']);
        $this->assertSame([], $config['routes'][0]['include']);
        $this->assertSame([], $config['routes'][0]['exclude']);

        // The particle-aware extraction, without which a generated spec is bare paths (ADR-0211 §9).
        $this->assertContains(GroupStrategy::class, $config['strategies']['metadata']);
        $this->assertContains(ParticleTitleStrategy::class, $config['strategies']['metadata']);
        $this->assertContains(RouteTitleStrategy::class, $config['strategies']['metadata']);
        $this->assertContains(ParticleRequestStrategy::class, $config['strategies']['bodyParameters']);
        $this->assertContains(ReturnsResponseStrategy::class, $config['strategies']['responses']);
        $this->assertContains(ParticleResponseStrategy::class, $config['strategies']['responses']);

        // A trait the two response strategies share — listing it as a strategy would blow up extraction.
        $this->assertNotContains(ModelsResponseEnvelope::class, $config['strategies']['responses']);

        // Beam ships the taxonomy EMPTY: a host's groups derive from its own declared particle resources,
        // never from one estate's ontology seeded into every host (ADR-0211 §10).
        $this->assertSame([], $config['groups']['order']);

        @unlink($published);
    }

    /**
     * ADR-0211 §4: a fresh host gets its artifact at install time. Scribe is not registered in this
     * package's testbench (beam boots with only its own provider), so the step must SKIP silently rather
     * than crash — the same shape as the pnpm-overrides delegation.
     */
    public function test_artifact_generation_skips_silently_when_scribe_is_not_registered(): void
    {
        $command = $this->app->make(BeamInstallCommand::class);

        $output = new BufferedOutput;
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        $method = new \ReflectionMethod($command, 'generateOpenApiArtifact');
        $method->setAccessible(true);
        $method->invoke($command);

        $this->assertSame('', $output->fetch());
    }

    public function test_a_consumer_self_registers_without_beam_naming_it(): void
    {
        $manifest = $this->app->make(BeamInstallManifest::class);
        // A consumer pushes DOWN into the manifest — exactly what beam-notifications' provider does.
        $manifest->register('acme/some-beam-package', ['acme-config'], order: 100);

        $names = array_map(fn ($s) => $s->package, $manifest->steps());
        $this->assertContains('acme/some-beam-package', $names);
        // Core still leads; the consumer follows.
        $this->assertSame(0, $manifest->steps()[0]->order);
    }

    public function test_beam_install_runs_core_first_and_succeeds(): void
    {
        $this->app->make(BeamInstallManifest::class)->register('acme/late', ['acme-config'], order: 100);

        // Non-interactive (CI/scripted) install: no prompts, pure Phase-1 manifest mechanics. The bare
        // `php artisan splicewire:beam:install` in a TTY is now the Phase-2 wizard (covered below).
        $this->artisan('splicewire:beam:install', ['--no-interaction' => true])
            ->expectsOutputToContain('splicewire:beam:install → splicewire/laravel-beam (core)')
            ->expectsOutputToContain('splicewire:beam:install → acme/late')
            ->expectsOutputToContain('beam stack installed.')
            ->assertExitCode(0);
    }

    /**
     * beam-install-turnkey: the command runs a verify pass over the three fresh-host provisioning traps
     * (shared migrations, nav disk, users table). The FIXES are package defaults; the command verifies +
     * reports, never fatal. Exercised here at the UNIT level (the private verify methods), because running
     * the whole command's migrate is fragile in this testbench (a pre-existing `media already exists` in the
     * shared-migration testbench setup — orthogonal to this pass). The reporting strings and non-fatal
     * behaviour are asserted directly.
     */
    public function test_verify_provisioning_reports_the_three_traps_without_throwing(): void
    {
        $command = $this->app->make(BeamInstallCommand::class);

        $output = new BufferedOutput;
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(
            new ArrayInput([]),
            $output,
        ));

        $method = new \ReflectionMethod($command, 'verifyProvisioning');
        $method->setAccessible(true);
        $method->invoke($command, 'single');

        $text = $output->fetch();
        $this->assertStringContainsString('verify provisioning', $text);
        $this->assertStringContainsString('trap 1 (shared migrations)', $text);
        $this->assertStringContainsString('trap 2 (nav disk)', $text);
        $this->assertStringContainsString('trap 3 (users table)', $text);
    }

    /**
     * Phase 2 (beam-particle-rename ticket 10): the wizard's answers, passed as options (the scriptable
     * form the interactive prompts also produce), take effect on runtime config BEFORE publish/migrate —
     * so a retrofit host that answers prefix `''` + schema-sources `file` completes setup from this one
     * command with no prefix and no beam_schemas source. This is the load-bearing "one command configures
     * the stack" behaviour; the prompts are just a front door onto these same values.
     */
    public function test_answers_configure_the_stack_for_a_retrofit_host(): void
    {
        $this->artisan('splicewire:beam:install', [
            '--prefix' => '',
            '--schema-sources' => 'file',
            '--tenancy' => 'multi',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('splicewire:beam:install → splicewire/laravel-beam (core)')
            ->assertExitCode(0);

        // The empty prefix means Beam::table() no longer prefixes — a retrofit host's tables stand as-is.
        $this->assertSame('', config('beam.core.table_prefix'));
        $this->assertSame('particles', Beam::table('particles'));
        // Filesystem-only schema store ⇒ no db source ⇒ no beam_schemas table provisioned.
        $this->assertSame(['file'], config('beam.core.schema.sources'));
        $this->assertSame('multi', config('beam.core.tenancy'));
    }

    /**
     * `--modules` filters WHICH optional packages install; core (order 0) always runs. An empty value
     * installs core only — the leanest retrofit.
     */
    public function test_modules_option_filters_optional_packages_core_always_runs(): void
    {
        $manifest = $this->app->make(BeamInstallManifest::class);
        $manifest->register('acme/wanted', ['wanted-config'], order: 100);
        $manifest->register('acme/skipped', ['skipped-config'], order: 100);

        $this->artisan('splicewire:beam:install', ['--modules' => 'wanted', '--no-interaction' => true])
            ->expectsOutputToContain('splicewire:beam:install → splicewire/laravel-beam (core)')
            ->expectsOutputToContain('splicewire:beam:install → acme/wanted')
            ->doesntExpectOutputToContain('acme/skipped')
            ->assertExitCode(0);

        $this->artisan('splicewire:beam:install', ['--modules' => '', '--no-interaction' => true])
            ->expectsOutputToContain('splicewire:beam:install → splicewire/laravel-beam (core)')
            ->doesntExpectOutputToContain('acme/wanted')
            ->assertExitCode(0);
    }

    /**
     * beam-install-turnkey ticket 12: `persistConfig()` is safe-unless-force, matching `vendor:publish` —
     * an already-published `config/beam/core.php` (which may carry hand edits) is left alone unless the
     * operator passes `--force`. The answered values still govern the run via runtime config either way
     * (asserted separately above); this test is about what lands on DISK.
     */
    public function test_persist_config_leaves_a_hand_edited_file_alone_without_force(): void
    {
        $path = base_path('config/beam/core.php');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "<?php\n\nreturn [\n    'table_prefix' => 'hand_edited_',\n    'schema' => ['sources' => ['db']],\n];\n");

        $this->artisan('splicewire:beam:install', [
            '--prefix' => 'new_',
            '--schema-sources' => 'file',
            '--tenancy' => 'single',
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $this->assertStringContainsString("'table_prefix' => 'hand_edited_'", File::get($path));
    }

    public function test_persist_config_overwrites_an_already_published_file_with_force(): void
    {
        $path = base_path('config/beam/core.php');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "<?php\n\nreturn [\n    'table_prefix' => 'hand_edited_',\n    'schema' => ['sources' => ['db']],\n];\n");

        $this->artisan('splicewire:beam:install', [
            '--prefix' => 'new_',
            '--schema-sources' => 'file',
            '--tenancy' => 'single',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertExitCode(0);

        $this->assertStringContainsString("'table_prefix' => 'new_'", File::get($path));
    }

    /**
     * A step's optional {@see InstallStep::$note} round-trips through
     * registration — a pointer to a config knob that's real but deliberately defaults to a no-op (e.g.
     * beam-ux's mirror_disk), so an operator installing the module doesn't have to discover it
     * independently. `BeamInstallCommand` prints it right after the step's package line (not covered by
     * an artisan-level test here — every artisan-level test in this file that runs a real `migrate`
     * pre-existingly collides on testbench's own skeleton migrations already applied in `setUp`).
     */
    public function test_a_steps_note_round_trips_through_registration(): void
    {
        $manifest = $this->app->make(BeamInstallManifest::class);
        $manifest->register('acme/noted', ['noted-config'], order: 100, note: 'Optionally set acme.thing for X.');
        $manifest->register('acme/silent', ['silent-config'], order: 100);

        $steps = array_values(array_filter(
            $manifest->steps(),
            fn ($s) => in_array($s->package, ['acme/noted', 'acme/silent'], true),
        ));
        $byPackage = array_combine(array_map(fn ($s) => $s->package, $steps), $steps);

        $this->assertSame('Optionally set acme.thing for X.', $byPackage['acme/noted']->note);
        $this->assertNull($byPackage['acme/silent']->note);
    }
}
