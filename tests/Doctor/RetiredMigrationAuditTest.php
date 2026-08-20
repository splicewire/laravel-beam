<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\RetiredMigrationAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * Reproduces the condition that produced 421 failures in `splicewire/tower`'s suite from one stale file:
 * a retired `tenant/create_activity_log_table` copy still published alongside the `shared/` stub that
 * superseded it.
 *
 * The load-bearing assertion is `test_the_surviving_shared_copy_is_never_condemned` — the retired copy
 * and the canonical one share a basename and differ only by directory, so a name-only match would report
 * the survivor and send someone to delete exactly the wrong file.
 */
class RetiredMigrationAuditTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/beam-retired-migrations-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/tenant', 0777, true);
        mkdir($this->root.'/shared', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['tenant', 'shared', ''] as $dir) {
            foreach ((array) glob(rtrim($this->root.'/'.$dir, '/').'/*.php') as $file) {
                is_string($file) && @unlink($file);
            }
        }
        @rmdir($this->root.'/tenant');
        @rmdir($this->root.'/shared');
        @rmdir($this->root);

        parent::tearDown();
    }

    private function publish(string $relative): void
    {
        file_put_contents($this->root.'/'.$relative, '<?php // fixture');
    }

    /** @return list<Finding> */
    private function audit(): array
    {
        return (new RetiredMigrationAudit(RetiredMigrationAudit::BEAM_RETIRED, $this->root))->run();
    }

    public function test_a_retired_tenant_copy_is_reported(): void
    {
        $this->publish('tenant/2026_08_13_195119_create_activity_log_table.php');
        $this->publish('shared/0001_01_01_000002_create_activity_log_table.php');

        $findings = $this->audit();

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('tenant/2026_08_13_195119_create_activity_log_table.php', $findings[0]->detail);
        $this->assertStringContainsString('shared/create_activity_log_table', $findings[0]->detail);
    }

    public function test_the_surviving_shared_copy_is_never_condemned(): void
    {
        // Only the canonical copy. A name-only match would flag it — the retired entry and the survivor
        // differ ONLY by directory.
        $this->publish('shared/0001_01_01_000002_create_activity_log_table.php');

        $findings = $this->audit();

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('No retired migrations', $findings[0]->detail);
    }

    public function test_a_retired_root_level_copy_is_reported(): void
    {
        $this->publish('2026_08_13_195114_create_central_activity_log_table.php');

        $this->assertStringContainsString(
            'create_central_activity_log_table',
            $this->audit()[0]->detail,
        );
    }

    public function test_the_timestamp_a_host_republished_under_does_not_matter(): void
    {
        // Publishing re-stamps, so the copy a host carries never has the timestamp it was retired at.
        $this->publish('tenant/2019_01_01_000000_create_activity_log_table.php');

        $this->assertStringContainsString('Retired migration still published', $this->audit()[0]->detail);
    }

    public function test_an_unrelated_migration_is_left_alone(): void
    {
        $this->publish('tenant/2026_08_13_195112_create_workflow_awaitings_table.php');

        $this->assertStringContainsString('No retired migrations', $this->audit()[0]->detail);
    }

    public function test_a_host_with_no_migrations_directory_passes(): void
    {
        $findings = (new RetiredMigrationAudit(RetiredMigrationAudit::BEAM_RETIRED, $this->root.'/absent'))->run();

        $this->assertStringContainsString('nothing to check', $findings[0]->detail);
    }
}
