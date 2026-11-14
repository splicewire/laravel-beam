<?php

namespace Splicewire\Beam\Tests\Seed;

use Illuminate\Database\Seeder;
use InvalidArgumentException;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Seed\SeedingScenario;
use Splicewire\Beam\Seed\SeedingScenarioRegistry;
use Splicewire\Beam\Seed\SeedStep;
use Splicewire\Beam\Tests\TestCase;

/**
 * seeding-scenarios 01: a seed step's memberships, the `beam.seed.scenarios` registry, and the ladder that chooses
 * a run's scenario (`--scenario`, `beam.seed.scenario`, the environment's name, `baseline`).
 */
class SeedingScenarioTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ScenarioRan::$ran = [];

        // Two memberships: one baseline step, one demo step, plus a second baseline step to exclude by coordinate.
        $this->app->instance(BeamSeedManifest::class, (new BeamSeedManifest)
            ->register('vendor/substrate', BaselineScenarioSeeder::class, order: 0)
            ->register('vendor/roles', RolesScenarioSeeder::class, order: 5)
            ->register('vendor/demo-team', DemoScenarioSeeder::class, order: 10, memberships: SeedStep::DEMO));
    }

    public function test_a_step_registered_without_a_membership_is_baseline(): void
    {
        $manifest = new BeamSeedManifest;
        $manifest->register('rushing/calcucrypt', BaselineScenarioSeeder::class);

        $this->assertSame([SeedStep::BASELINE], $manifest->steps()[0]->memberships);
    }

    public function test_beam_core_ships_baseline_and_local_and_declares_the_ladder(): void
    {
        $scenarios = $this->app->make(SeedingScenarioRegistry::class);

        $this->assertSame(['baseline', 'local'], $scenarios->names());
        $this->assertSame([SeedStep::DEMO], $scenarios->resolve('local')->include);
        $this->assertSame(['option', 'config', 'environment', 'baseline'], $scenarios->rungs());
        $this->assertSame('splicewire/laravel-beam', $scenarios->registrantOf('local'));
    }

    public function test_each_rung_selects_and_a_higher_rung_wins(): void
    {
        $scenarios = $this->app->make(SeedingScenarioRegistry::class);
        config(['beam.seed.scenarios.staging' => ['include' => [SeedStep::DEMO]]]);

        // baseline: an environment with no scenario of its name
        $chosen = $scenarios->select(null, 'testing');
        $this->assertSame(['baseline', 'baseline'], [$chosen->scenario->name, $chosen->rung]);

        // environment beats baseline
        $chosen = $scenarios->select(null, 'local');
        $this->assertSame(['local', 'environment'], [$chosen->scenario->name, $chosen->rung]);

        // config beats environment
        config(['beam.seed.scenario' => 'staging']);
        $chosen = $scenarios->select(null, 'local');
        $this->assertSame(['staging', 'config'], [$chosen->scenario->name, $chosen->rung]);

        // the option beats config
        $chosen = $scenarios->select('baseline', 'local');
        $this->assertSame(['baseline', 'option'], [$chosen->scenario->name, $chosen->rung]);
    }

    public function test_each_rung_runs_its_steps_through_the_command(): void
    {
        $this->app['env'] = 'testing';
        $this->artisan('splicewire:beam:seed')
            ->expectsOutputToContain('scenario baseline (selected by baseline)')
            ->expectsOutputToContain('vendor/demo-team: left out by scenario baseline (does not include demo)')
            ->assertExitCode(0);
        $this->assertSame([BaselineScenarioSeeder::class, RolesScenarioSeeder::class], ScenarioRan::$ran);

        ScenarioRan::$ran = [];
        $this->app['env'] = 'local';
        $this->artisan('splicewire:beam:seed')
            ->expectsOutputToContain('scenario local (selected by environment)')
            ->assertExitCode(0);
        $this->assertContains(DemoScenarioSeeder::class, ScenarioRan::$ran);

        ScenarioRan::$ran = [];
        config(['beam.seed.scenario' => 'baseline']);
        $this->artisan('splicewire:beam:seed')
            ->expectsOutputToContain('scenario baseline (selected by config)')
            ->assertExitCode(0);
        $this->assertNotContains(DemoScenarioSeeder::class, ScenarioRan::$ran);

        ScenarioRan::$ran = [];
        $this->artisan('splicewire:beam:seed', ['--scenario' => 'local'])
            ->expectsOutputToContain('scenario local (selected by option)')
            ->expectsOutputToContain('3 seeded, 0 skipped (gate off), 0 FAILED, 0 left out by the scenario.')
            ->assertExitCode(0);
        $this->assertContains(DemoScenarioSeeder::class, ScenarioRan::$ran);
    }

    public function test_a_host_config_exclude_removes_a_step_a_package_scenario_included_and_the_command_reports_it(): void
    {
        // The package's `local` includes demo and runs every baseline step; the host's `local` wins wholesale and
        // drops one baseline step by coordinate.
        config(['beam.seed.scenarios.local' => ['include' => [SeedStep::DEMO], 'exclude' => ['vendor/roles']]]);

        $this->artisan('splicewire:beam:seed', ['--scenario' => 'local'])
            ->expectsOutputToContain('vendor/roles: left out by scenario local (excluded by coordinate)')
            ->expectsOutputToContain('2 seeded, 0 skipped (gate off), 0 FAILED, 1 left out by the scenario.')
            ->assertExitCode(0);

        $this->assertSame([BaselineScenarioSeeder::class, DemoScenarioSeeder::class], ScenarioRan::$ran);
    }

    public function test_a_left_out_step_is_reported_apart_from_a_gated_skip(): void
    {
        config(['test.scenario_gate' => false]);
        $this->app->make(BeamSeedManifest::class)
            ->register('vendor/gated', BaselineScenarioSeeder::class, order: 20, configGate: 'test.scenario_gate');

        $this->artisan('splicewire:beam:seed', ['--scenario' => 'baseline'])
            ->expectsOutputToContain('vendor/gated: skipped (test.scenario_gate is off)')
            ->expectsOutputToContain('vendor/demo-team: left out by scenario baseline')
            ->expectsOutputToContain('2 seeded, 1 skipped (gate off), 0 FAILED, 1 left out by the scenario.')
            ->assertExitCode(0);
    }

    public function test_an_unknown_name_given_explicitly_is_an_error(): void
    {
        $this->artisan('splicewire:beam:seed', ['--scenario' => 'nope'])
            ->expectsOutputToContain('Unknown seeding scenario [nope] from --scenario')
            ->assertExitCode(1);

        config(['beam.seed.scenario' => 'also-nope']);
        $this->artisan('splicewire:beam:seed')
            ->expectsOutputToContain('Unknown seeding scenario [also-nope] from beam.seed.scenario (BEAM_SEED_SCENARIO)')
            ->assertExitCode(1);

        $this->assertSame([], ScenarioRan::$ran);
    }

    public function test_in_production_no_demo_step_runs_unless_a_scenario_says_so(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('splicewire:beam:seed', ['--force' => true])
            ->expectsOutputToContain('scenario baseline (selected by baseline)')
            ->assertExitCode(0);
        $this->assertNotContains(DemoScenarioSeeder::class, ScenarioRan::$ran);

        ScenarioRan::$ran = [];
        $this->artisan('splicewire:beam:seed', ['--force' => true, '--scenario' => 'local'])->assertExitCode(0);
        $this->assertContains(DemoScenarioSeeder::class, ScenarioRan::$ran);
    }

    public function test_install_passes_scenario_through_to_its_seed_pass(): void
    {
        $this->app['env'] = 'testing';
        // One step that publishes and migrates nothing, so the install reaches its seed pass and touches no files.
        $this->app->instance(BeamInstallManifest::class, (new BeamInstallManifest)->register('fixture/noop', [], migrates: false));

        $this->artisan('splicewire:beam:install', ['--no-interaction' => true, '--skip-preflight' => true, '--scenario' => 'local'])
            ->expectsOutputToContain('scenario local (selected by option)')
            ->assertExitCode(0);

        $this->assertContains(DemoScenarioSeeder::class, ScenarioRan::$ran);
    }

    public function test_exclude_may_name_a_baseline_coordinate_but_never_the_baseline_membership(): void
    {
        $scenario = new SeedingScenario('lean', exclude: ['vendor/roles']);
        $this->assertFalse($scenario->selects(new SeedStep('vendor/roles', RolesScenarioSeeder::class)));
        $this->assertTrue($scenario->selects(new SeedStep('vendor/substrate', BaselineScenarioSeeder::class)));

        $this->expectException(InvalidArgumentException::class);
        new SeedingScenario('empty', exclude: [SeedStep::BASELINE]);
    }

    public function test_a_membership_exclude_removes_a_step_a_coordinate_include_named(): void
    {
        $scenario = new SeedingScenario('mixed', include: ['vendor/demo-team'], exclude: [SeedStep::DEMO]);

        $this->assertSame('excludes demo', $scenario->leftOutBecause(
            new SeedStep('vendor/demo-team', DemoScenarioSeeder::class, memberships: SeedStep::DEMO),
        ));
    }
}

class ScenarioRan
{
    /** @var list<string> */
    public static array $ran = [];
}

class BaselineScenarioSeeder extends Seeder
{
    public function run(): void
    {
        ScenarioRan::$ran[] = self::class;
    }
}

class RolesScenarioSeeder extends Seeder
{
    public function run(): void
    {
        ScenarioRan::$ran[] = self::class;
    }
}

class DemoScenarioSeeder extends Seeder
{
    public function run(): void
    {
        ScenarioRan::$ran[] = self::class;
    }
}
