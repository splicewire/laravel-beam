<?php

namespace Splicewire\Beam\Tests\Doctor;

use Splicewire\Beam\Doctor\BeamCoreMigrationsAudit;
use Splicewire\Beam\Doctor\Testing\AssertsStubMigrations;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-core's own operator check: its migrations must stay publish-only .stub files. Mirrors the
 * per-package `DeclaredTopologyTest` shape (`rushing/php-package-topology`'s `AssertsDeclaredTopology`) —
 * a thin test wrapping a shared engine, declaring only "which audit is mine."
 */
class BeamCoreMigrationsAuditTest extends TestCase
{
    use AssertsStubMigrations;

    public function test_beam_core_migrations_are_publish_only_stubs(): void
    {
        $this->assertMigrationsArePublishOnlyStubs();
    }

    protected function stubMigrationsAuditClass(): string
    {
        return BeamCoreMigrationsAudit::class;
    }
}
