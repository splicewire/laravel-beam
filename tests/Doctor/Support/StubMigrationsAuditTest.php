<?php

namespace Splicewire\Beam\Tests\Doctor\Support;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\Support\StubMigrationsAudit;
use Splicewire\Beam\Tests\Doctor\Support\Fixtures\CleanStub\Src\CleanStubProvider;
use Splicewire\Beam\Tests\Doctor\Support\Fixtures\HostSharedMigrationsProvider;
use Splicewire\Beam\Tests\Doctor\Support\Fixtures\LoadMigrationsProvider;
use Splicewire\Beam\Tests\Doctor\Support\Fixtures\NoHasMigrationsProvider;
use Splicewire\Beam\Tests\Doctor\Support\Fixtures\RealPhp\Src\RealPhpProvider;
use Splicewire\Beam\Tests\TestCase;

class StubMigrationsAuditTest extends TestCase
{
    public function test_passes_when_the_provider_uses_has_migrations_and_ships_only_stubs(): void
    {
        $finding = $this->auditFor(CleanStubProvider::class)->run()[0];

        $this->assertSame(DoctorStatus::Pass, $finding->status);
    }

    public function test_fails_when_the_provider_calls_load_migrations_from(): void
    {
        $finding = $this->auditFor(LoadMigrationsProvider::class)->run()[0];

        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('loadMigrationsFrom', $finding->detail);
    }

    public function test_passes_when_load_migrations_from_targets_database_path(): void
    {
        $finding = $this->auditFor(HostSharedMigrationsProvider::class)->run()[0];

        $this->assertSame(DoctorStatus::Pass, $finding->status);
    }

    public function test_warns_when_the_provider_registers_no_has_migrations_call(): void
    {
        $finding = $this->auditFor(NoHasMigrationsProvider::class)->run()[0];

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('hasMigrations', $finding->detail);
    }

    public function test_fails_when_a_real_non_stub_migration_file_sits_alongside_the_stubs(): void
    {
        $finding = $this->auditFor(RealPhpProvider::class)->run()[0];

        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('create_bar_table.php', $finding->detail);
    }

    /**
     * @param  class-string  $providerClass
     */
    private function auditFor(string $providerClass): StubMigrationsAudit
    {
        return new class($providerClass) extends StubMigrationsAudit
        {
            public function __construct(private string $providerClass) {}

            protected function packageName(): string
            {
                return 'fixture/package';
            }

            protected function serviceProviderClass(): string
            {
                return $this->providerClass;
            }
        };
    }
}
