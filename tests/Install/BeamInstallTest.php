<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Install;

use Splicewire\Beam\Beam;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Tests\TestCase;

/**
 * The beam-install orchestrator + self-registration manifest (beam-write-pipeline ticket 08): one
 * command sets up the whole stack, and a package joins by registering its OWN step — beam-core never
 * names a consumer, and steps run core-first.
 */
class BeamInstallTest extends TestCase
{
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
        $this->assertContains('laravel-beam-config', $core->publishTags);
        $this->assertContains('laravel-beam-migrations', $core->publishTags);
        $this->assertTrue($core->migrates);
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
        // `php artisan beam:install` in a TTY is now the Phase-2 wizard (covered below).
        $this->artisan('beam:install', ['--no-interaction' => true])
            ->expectsOutputToContain('beam:install → splicewire/laravel-beam (core)')
            ->expectsOutputToContain('beam:install → acme/late')
            ->expectsOutputToContain('beam stack installed.')
            ->assertExitCode(0);
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
        $this->artisan('beam:install', [
            '--prefix' => '',
            '--schema-sources' => 'file',
            '--tenancy' => 'multi',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('beam:install → splicewire/laravel-beam (core)')
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

        $this->artisan('beam:install', ['--modules' => 'wanted', '--no-interaction' => true])
            ->expectsOutputToContain('beam:install → splicewire/laravel-beam (core)')
            ->expectsOutputToContain('beam:install → acme/wanted')
            ->doesntExpectOutputToContain('acme/skipped')
            ->assertExitCode(0);

        $this->artisan('beam:install', ['--modules' => '', '--no-interaction' => true])
            ->expectsOutputToContain('beam:install → splicewire/laravel-beam (core)')
            ->doesntExpectOutputToContain('acme/wanted')
            ->assertExitCode(0);
    }
}
