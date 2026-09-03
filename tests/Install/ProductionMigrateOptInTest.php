<?php

namespace Splicewire\Beam\Tests\Install;

use Illuminate\Support\Facades\File;
use Laravel\Prompts\Prompt;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-facade 190 (owner-ruled 2026-08-28 on beam-docs-satellite 31): `--force` means "overwrite my
 * published files" and nothing else. The production-write waiver — `migrate --force`, `db:seed --force`
 * — is its own opt-in, `--allow-production-migrate`, default off.
 *
 * The production branch is manufactured here by setting the container's `env` binding, which is what
 * `ConfirmableTrait::getDefaultConfirmCallback()` reads. ⚠️ That same binding turns
 * `runningUnitTests()` OFF, and with it the per-run `Prompt::fallbackWhen(...)` that routes a confirm
 * to the mocked `askQuestion` — except that the fallback flag is STICKY (`$condition || $shouldFallback`),
 * so it is off when this file runs alone and on after any earlier test ran a command. Measured:
 * green alone, `askQuestion(): no expectations were specified` inside the full suite. Pinning
 * `Prompt::fallbackWhen(true)` makes both orderings the same path, and then `expectsConfirmation`
 * is the instrument: an asked-and-declined confirm is distinguishable from one never asked, and a
 * confirm asked where none is expected fails the test.
 *
 * Verified to fail against the pre-190 code: the first test exited 0 and its confirm was never
 * asked (`--force` was forwarded to `migrate` and waived it), and the second threw
 * `InvalidOptionException` on the undeclared option.
 */
class ProductionMigrateOptInTest extends TestCase
{
    /** @see BeamInstallTest::tearDown() — the real publish writes into the shared testbench skeleton. */
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

    public function test_in_production_force_does_not_waive_the_migrate_confirm_and_a_declined_migrate_fails_the_install(): void
    {
        $this->app['env'] = 'production';
        Prompt::fallbackWhen(true);

        $this->artisan('splicewire:beam:install', ['--force' => true, '--no-interaction' => true])
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->expectsOutputToContain('Command cancelled.')
            ->expectsOutputToContain('migrate did not complete')
            ->assertExitCode(1);
    }

    public function test_the_opt_in_waives_the_production_confirm_and_the_install_completes(): void
    {
        $this->app['env'] = 'production';
        Prompt::fallbackWhen(true);

        // No expectsConfirmation: a confirm asked here fails the test as an unexpected question.
        $this->artisan('splicewire:beam:install', ['--allow-production-migrate' => true, '--no-interaction' => true])
            ->expectsOutputToContain('beam stack installed.')
            ->assertExitCode(0);
    }

    public function test_outside_production_nothing_is_asked_and_no_opt_in_is_needed(): void
    {
        $this->assertFalse($this->app->environment('production'));

        $this->artisan('splicewire:beam:install', ['--no-interaction' => true])
            ->expectsOutputToContain('beam stack installed.')
            ->assertExitCode(0);
    }
}
