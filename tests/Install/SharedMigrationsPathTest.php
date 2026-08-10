<?php

namespace Splicewire\Beam\Tests\Install;

use Illuminate\Database\Migrations\Migrator;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-install-turnkey trap 1: every beam-* package publishes its ubiquitous tables into ONE destination,
 * `database/migrations/shared/` — a SUBDIR the stock framework migrator never recurses into. On a
 * SINGLE-TENANT host (no beam-tenancy), those stubs would be published yet silently NOT migrated.
 *
 * beam-core's boot closes this as a DEFAULT: when the tenancy provider is ABSENT it registers the shared
 * directory for the central migrate pass, so a fresh single-tenant host migrates the shared tables even
 * without running `splicewire:beam:install`. GUARDED on beam-tenancy not being present so it never
 * double-registers on a multi-tenant host (that package owns the path). This test's TestCase does NOT load
 * beam-tenancy, so beam-core takes ownership — exactly the single-tenant path.
 */
class SharedMigrationsPathTest extends TestCase
{
    public function test_beam_core_registers_the_shared_migrations_path_for_a_single_tenant_host(): void
    {
        // The tenancy provider must NOT be present in this suite — that is the single-tenant precondition.
        $this->assertFalse(
            class_exists('Splicewire\Beam\Tenancy\BeamMultiTenancyServiceProvider'),
            'beam-tenancy must be absent for the single-tenant fallback to engage',
        );

        /** @var Migrator $migrator */
        $migrator = $this->app->make('migrator');

        $shared = database_path('migrations/shared');

        $this->assertContains(
            $shared,
            $migrator->paths(),
            'beam-core must register database/migrations/shared for the central migrate pass on a single-tenant host',
        );
    }

    public function test_the_ownership_guard_reports_tenancy_absent(): void
    {
        // The guard the boot check keys on — false here (no tenancy package) ⇒ beam-core owns the path.
        $provider = new BeamServiceProvider($this->app);

        $method = new \ReflectionMethod($provider, 'sharedMigrationsOwnedByTenancy');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($provider));
    }
}
