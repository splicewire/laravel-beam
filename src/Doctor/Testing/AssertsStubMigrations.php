<?php

namespace Splicewire\Beam\Doctor\Testing;

use LogicException;
use Rushing\Doctor\DoctorRegistration;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\Support\StubMigrationsAudit;

/**
 * Test-time counterpart of {@see StubMigrationsAudit} — a package's own test suite asserts against its
 * OWN declaration, mirroring `rushing/php-package-topology`'s `AssertsDeclaredTopology`: the engine
 * lives once, each consumer supplies the one thing it can't share (which package is "mine").
 *
 * A package declares itself with {@see BeamDoctorManifest::stubMigrations()}, so its test overrides
 * {@see stubMigrationsPackage()} and {@see stubMigrationsProvider()} with the same two values. The audit
 * is then built exactly as that declaration builds it. Where the harness boots beam, so the manifest is
 * bound, the test also checks the provider really made the declaration. Most package harnesses do not
 * boot beam's provider, and there the `bound()`-guarded declaration never runs.
 *
 * The retired form, a per-package subclass named by {@see stubMigrationsAuditClass()}, still works for a
 * package that has not migrated yet.
 */
trait AssertsStubMigrations
{
    /** The package this test's provider declares with {@see BeamDoctorManifest::stubMigrations()}. */
    protected function stubMigrationsPackage(): ?string
    {
        return null;
    }

    /**
     * The provider named in that declaration, whose `->hasMigrations([...])` publishes them.
     *
     * @return class-string|null
     */
    protected function stubMigrationsProvider(): ?string
    {
        return null;
    }

    /**
     * The retired form: this package's own subclass.
     *
     * @return class-string<StubMigrationsAudit>
     */
    protected function stubMigrationsAuditClass(): string
    {
        throw new LogicException(
            static::class.' names neither a declaration (stubMigrationsPackage() and stubMigrationsProvider()) nor a subclass.',
        );
    }

    protected function assertMigrationsArePublishOnlyStubs(): void
    {
        $package = $this->stubMigrationsPackage();

        if ($package === null) {
            $audit = new ($this->stubMigrationsAuditClass());
        } else {
            $provider = $this->stubMigrationsProvider()
                ?? throw new LogicException(static::class.' names its package but not its provider.');

            $this->assertDeclaredWhereBeamBoots($package, $provider);

            $audit = new StubMigrationsAudit($package, $provider);
        }

        $findings = $audit->run();

        $this->assertNotEmpty($findings);

        foreach ($findings as $finding) {
            $this->assertNotSame(
                DoctorStatus::Fail,
                $finding->status,
                "[{$finding->check}] {$finding->detail}",
            );
        }
    }

    /**
     * @param  class-string  $provider
     */
    private function assertDeclaredWhereBeamBoots(string $package, string $provider): void
    {
        if (! isset($this->app) || ! $this->app->bound(BeamDoctorManifest::class)) {
            return;
        }

        $registration = $this->app->make(BeamDoctorManifest::class)
            ->tryResolve(BeamDoctorManifest::stubMigrationsKey($package));

        $this->assertInstanceOf(
            DoctorRegistration::class,
            $registration,
            "{$package} does not declare its stub migrations: call BeamDoctorManifest::stubMigrations() from its provider's boot().",
        );
        $this->assertSame(['package' => $package, 'provider' => $provider], $registration->with);
    }
}
