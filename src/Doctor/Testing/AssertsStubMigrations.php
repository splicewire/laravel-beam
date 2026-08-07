<?php

namespace Splicewire\Beam\Doctor\Testing;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\Support\StubMigrationsAudit;

/**
 * Test-time counterpart of {@see StubMigrationsAudit} — a package's own test suite asserts against its
 * OWN audit subclass, mirroring `rushing/php-package-topology`'s `AssertsDeclaredTopology`: the engine
 * lives once, each consumer supplies the one thing it can't share (which audit is "mine").
 */
trait AssertsStubMigrations
{
    /**
     * @return class-string<StubMigrationsAudit>
     */
    abstract protected function stubMigrationsAuditClass(): string;

    protected function assertMigrationsArePublishOnlyStubs(): void
    {
        $audit = new ($this->stubMigrationsAuditClass());

        foreach ($audit->run() as $finding) {
            $this->assertNotSame(
                DoctorStatus::Fail,
                $finding->status,
                "[{$finding->check}] {$finding->detail}",
            );
        }
    }
}
