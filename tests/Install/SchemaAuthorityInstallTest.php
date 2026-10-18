<?php

namespace Splicewire\Beam\Tests\Install;

use Illuminate\Support\Facades\File;
use Splicewire\Beam\Tests\TestCase;

/**
 * The install writes the host's OWN schema authority (owner-ruled 2026-10-18, launch 00 21:52Z):
 * versioned `$id`s are write-once so no package default may exist, but a fresh host should declare
 * its authority at install instead of meeting MissingSchemaBaseUri's 503 at its first Studio
 * request. The option wins over any prompt, "false" is the declared opt-out, and the value lands on
 * the exact `.env` line the exception names.
 */
class SchemaAuthorityInstallTest extends TestCase
{
    private ?string $priorEnv = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->priorEnv = File::exists(base_path('.env')) ? File::get(base_path('.env')) : null;
    }

    /** @see ProductionMigrateOptInTest::tearDown() — the real publish writes into the shared skeleton. */
    protected function tearDown(): void
    {
        $this->priorEnv === null
            ? @unlink(base_path('.env'))
            : File::put(base_path('.env'), $this->priorEnv);

        File::deleteDirectory(base_path('config/beam'));

        foreach (['', '/shared', '/tenant'] as $sub) {
            $dir = base_path('database/migrations'.$sub);
            foreach ([
                ...glob($dir.'/[0-9]*_create_beam_*.php') ?: [],
                ...glob($dir.'/[0-9]*_create_media_table.php') ?: [],
            ] as $file) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    private function install(string $authority): void
    {
        $this->artisan('splicewire:beam:install', [
            '--schema-authority' => $authority,
            '--modules' => '',
            '--prefix' => 'beam_',
            '--tenancy' => 'single',
            '--schema-sources' => 'db,file',
            '--no-seed' => true,
        ])->assertSuccessful();
    }

    public function test_the_option_writes_the_env_line_and_applies_runtime_config(): void
    {
        File::put(base_path('.env'), "APP_NAME=probe\n");

        $this->install('https://probe.test/schemas');

        $this->assertStringContainsString(
            'SCHEMA_BASE_URI=https://probe.test/schemas',
            File::get(base_path('.env')),
        );
        $this->assertSame('https://probe.test/schemas', config('data-schemas.base_uri'));
    }

    public function test_false_is_a_declared_opt_out_and_replaces_an_existing_line(): void
    {
        File::put(base_path('.env'), "SCHEMA_BASE_URI=https://old.test/schemas\nAPP_NAME=probe\n");

        $this->install('false');

        $env = File::get(base_path('.env'));
        $this->assertStringContainsString('SCHEMA_BASE_URI=false', $env);
        $this->assertStringNotContainsString('old.test', $env);
        $this->assertFalse(config('data-schemas.base_uri'));
    }
}
