<?php

namespace Splicewire\Beam\Tests\Seed;

use Illuminate\Database\Seeder;
use Laravel\Prompts\Prompt;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-facade 190, Scope 2: `splicewire:beam:seed` declared `--force` and never read it — `db:seed`
 * was forced unconditionally, so a production host was seeded without being asked. The option now
 * gates the pass-through, and a declined production confirm is recorded as a failure (a warn that
 * scrolls away is the silence 143/46 were about).
 *
 * Asserts on the seeder's EFFECT, not only the exit code: `BeamSeedCommand` tolerates a seeder that
 * does nothing, so an exit-code-only test would pass against a seeder that silently never ran.
 *
 * The production branch is manufactured by setting the `env` binding; `Prompt::fallbackWhen(true)`
 * pins the confirm onto the mocked `askQuestion` in every test ordering (the flag is sticky, so it
 * differs between a lone run and the full suite — see ProductionMigrateOptInTest), which makes
 * `expectsConfirmation` the instrument.
 *
 * Verified to fail against the pre-190 code: the first test's seeder ran (`$ran === 1`), its confirm
 * was never asked, and the command exited 0.
 */
class SeedProductionOptInTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ProductionProbeSeeder::$ran = 0;
    }

    public function test_in_production_without_force_the_seed_is_declined_and_recorded(): void
    {
        $this->app['env'] = 'production';
        Prompt::fallbackWhen(true);
        $this->app->make(BeamSeedManifest::class)->register('vendor/probe', ProductionProbeSeeder::class);

        $this->artisan('splicewire:beam:seed', ['--no-interaction' => true])
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->expectsOutputToContain('Command cancelled.')
            ->expectsOutputToContain('production confirm declined')
            ->expectsOutputToContain('1 FAILED')
            ->assertExitCode(1);

        $this->assertSame(0, ProductionProbeSeeder::$ran);
    }

    public function test_in_production_force_waives_the_confirm_and_the_seeder_runs(): void
    {
        $this->app['env'] = 'production';
        Prompt::fallbackWhen(true);
        $this->app->make(BeamSeedManifest::class)->register('vendor/probe', ProductionProbeSeeder::class);

        $this->artisan('splicewire:beam:seed', ['--force' => true, '--no-interaction' => true])
            ->expectsOutputToContain('beam stack seeded.')
            ->assertExitCode(0);

        $this->assertSame(1, ProductionProbeSeeder::$ran);
    }

    public function test_outside_production_the_seeder_runs_without_force(): void
    {
        $this->assertFalse($this->app->environment('production'));
        $this->app->make(BeamSeedManifest::class)->register('vendor/probe', ProductionProbeSeeder::class);

        $this->artisan('splicewire:beam:seed', ['--no-interaction' => true])
            ->expectsOutputToContain('beam stack seeded.')
            ->assertExitCode(0);

        $this->assertSame(1, ProductionProbeSeeder::$ran);
    }
}

class ProductionProbeSeeder extends Seeder
{
    public static int $ran = 0;

    public function run(): void
    {
        self::$ran++;
    }
}
