<?php

namespace Splicewire\Beam\Tests\Seed;

use Illuminate\Database\Seeder;
use Splicewire\Beam\Console\BeamSeedCommand;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Tests\TestCase;

/**
 * The seed-side aggregation manifest (the twin of BeamInstallManifest / BeamDoctorManifest): a package
 * registers its own {@see \Splicewire\Beam\Seed\SeedStep} DOWN into it, and `splicewire:beam:seed` iterates
 * the manifest core-first, skipping any step whose config gate is off and tolerating a seeder that throws.
 */
class BeamSeedManifestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RanFlag::$ran = [];
    }

    // ---- Manifest unit (ordering + idempotency) -------------------------------------

    public function test_the_manifest_orders_steps_core_first(): void
    {
        $manifest = new BeamSeedManifest;
        $manifest->register('a-consumer', PassingStubSeeder::class, order: 100);
        $manifest->register('z-core', PassingStubSeeder::class, order: 0);
        $manifest->register('m-consumer', PassingStubSeeder::class, order: 100);

        $order = array_map(fn ($s) => $s->package, $manifest->steps());

        // Core (order 0) first; ties keep registration order (usort is stable since PHP 8).
        $this->assertSame(['z-core', 'a-consumer', 'm-consumer'], $order);
    }

    public function test_registration_is_idempotent_per_package(): void
    {
        $manifest = new BeamSeedManifest;
        $manifest->register('dup', PassingStubSeeder::class);
        $manifest->register('dup', GatedStubSeeder::class);

        $steps = $manifest->steps();
        $this->assertCount(1, $steps);
        $this->assertSame(GatedStubSeeder::class, $steps[0]->seeder);
    }

    public function test_beam_core_binds_the_manifest_as_a_singleton(): void
    {
        $a = $this->app->make(BeamSeedManifest::class);
        $b = $this->app->make(BeamSeedManifest::class);

        $this->assertSame($a, $b);
    }

    // ---- Command aggregation (runs, gates, tolerates throws, honors order) -----------

    public function test_the_command_runs_a_registered_seeder(): void
    {
        $this->app->make(BeamSeedManifest::class)
            ->register('vendor/probe', PassingStubSeeder::class);

        $this->artisan('splicewire:beam:seed')
            ->expectsOutputToContain('vendor/probe')
            ->expectsOutputToContain('beam stack seeded.')
            ->assertExitCode(0);

        $this->assertContains(PassingStubSeeder::class, RanFlag::$ran);
    }

    public function test_a_step_whose_config_gate_is_false_is_skipped(): void
    {
        config(['test.seed_gate' => false]);

        $this->app->make(BeamSeedManifest::class)
            ->register('vendor/gated', GatedStubSeeder::class, configGate: 'test.seed_gate');

        $this->artisan('splicewire:beam:seed')
            ->expectsOutputToContain('skipped (test.seed_gate is off)')
            ->assertExitCode(0);

        $this->assertNotContains(GatedStubSeeder::class, RanFlag::$ran);
    }

    public function test_a_step_whose_config_gate_is_true_runs(): void
    {
        config(['test.seed_gate' => true]);

        $this->app->make(BeamSeedManifest::class)
            ->register('vendor/gated', GatedStubSeeder::class, configGate: 'test.seed_gate');

        $this->artisan('splicewire:beam:seed')->assertExitCode(0);

        $this->assertContains(GatedStubSeeder::class, RanFlag::$ran);
    }

    public function test_a_throwing_seeder_is_non_fatal_and_the_run_continues(): void
    {
        $manifest = $this->app->make(BeamSeedManifest::class);
        // A brittle seeder registered BEFORE a healthy one; the run must reach the healthy one.
        $manifest->register('vendor/brittle', ThrowingStubSeeder::class, order: 10);
        $manifest->register('vendor/healthy', PassingStubSeeder::class, order: 20);

        $this->artisan('splicewire:beam:seed')
            ->expectsOutputToContain('failed (continuing)')
            ->expectsOutputToContain('beam stack seeded.')
            ->assertExitCode(0);

        // The healthy seeder still ran despite the earlier throw.
        $this->assertContains(PassingStubSeeder::class, RanFlag::$ran);
    }

    public function test_the_command_reports_when_nothing_is_registered(): void
    {
        // A fresh manifest instance (no beam-* consumer registered here).
        $this->app->instance(BeamSeedManifest::class, new BeamSeedManifest);

        $this->artisan('splicewire:beam:seed')
            ->expectsOutputToContain('nothing registered')
            ->assertExitCode(0);
    }
}

/** Shared marker so tests can assert which seeders actually executed. */
class RanFlag
{
    /** @var list<string> */
    public static array $ran = [];
}

class PassingStubSeeder extends Seeder
{
    public function run(): void
    {
        RanFlag::$ran[] = self::class;
    }
}

class GatedStubSeeder extends Seeder
{
    public function run(): void
    {
        RanFlag::$ran[] = self::class;
    }
}

class ThrowingStubSeeder extends Seeder
{
    public function run(): void
    {
        RanFlag::$ran[] = self::class;
        throw new \RuntimeException('brittle seeder blew up');
    }
}
