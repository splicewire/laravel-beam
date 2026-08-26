<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\LedgerAheadOfRepositoryAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * Reproduces the condition measured at `rushing/audiostud` (beam-facade ticket 110): a ledger that names
 * `create_ranks_table` and `create_rank_trees_table` while the repository holds neither file, because the
 * publish that wrote them was run and never committed. Twelve `RanksTest` cases passed against the dev
 * database and failed against every fresh one, and nothing in the estate reported the reason.
 *
 * The load-bearing assertion is `test_a_file_outside_a_registered_path_does_not_absolve_its_ledger_row` —
 * an orphan is defined by what the MIGRATOR can reach, not by what a recursive walk can find, because a
 * file in an unregistered subdirectory is exactly as absent as one that does not exist.
 */
class LedgerAheadOfRepositoryAuditTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/beam-ledger-ahead-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/shared', 0777, true);
        mkdir($this->root.'/unregistered', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['shared', 'unregistered', ''] as $dir) {
            foreach ((array) glob(rtrim($this->root.'/'.$dir, '/').'/*.php') as $file) {
                is_string($file) && @unlink($file);
            }
            $dir === '' || @rmdir($this->root.'/'.$dir);
        }
        @rmdir($this->root);

        parent::tearDown();
    }

    private function publish(string $relative): void
    {
        file_put_contents($this->root.'/'.$relative, '<?php // fixture');
    }

    /**
     * @param  list<string>  $ledger
     * @param  list<string>|null  $paths
     */
    private function audit(array $ledger, ?array $paths = null): LedgerAheadOfRepositoryAudit
    {
        return new LedgerAheadOfRepositoryAudit($ledger, $paths ?? [$this->root, $this->root.'/shared']);
    }

    public function test_a_ledger_row_with_no_file_is_reported(): void
    {
        $this->publish('shared/2026_08_11_160136_create_teams_table.php');

        $findings = $this->audit([
            '2026_08_11_160136_create_teams_table',
            '2026_08_11_000200_create_ranks_table',
        ])->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('2026_08_11_000200_create_ranks_table', $findings[0]->detail);
        $this->assertStringNotContainsString('create_teams_table', $findings[0]->detail);
    }

    public function test_a_ledger_whose_every_row_has_a_file_passes(): void
    {
        $this->publish('shared/2026_08_11_000100_create_rank_trees_table.php');
        $this->publish('2026_08_11_160136_create_teams_table.php');

        $findings = $this->audit([
            '2026_08_11_000100_create_rank_trees_table',
            '2026_08_11_160136_create_teams_table',
        ])->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    /**
     * The timestamp is part of the ledger's key, unlike every OTHER migration audit in the estate, which
     * matches on the timestamp-STRIPPED stem. That difference is deliberate: a stem match asks "did this
     * host ever publish this stub", while the ledger records the exact filename Laravel ran and will
     * re-run anything whose filename it does not recognise. A host that re-published the same stub under
     * a fresh timestamp genuinely has an orphan row — the old name will never be matched again.
     */
    public function test_a_republish_under_a_new_timestamp_leaves_the_old_row_orphaned(): void
    {
        $this->publish('shared/2026_08_26_043713_create_ranks_table.php');

        $findings = $this->audit(['2026_08_11_000200_create_ranks_table'])->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('2026_08_11_000200_create_ranks_table', $findings[0]->detail);
    }

    public function test_a_file_outside_a_registered_path_does_not_absolve_its_ledger_row(): void
    {
        // Present on disk, unreachable by the migrator — `unregistered/` is not in the scanned paths.
        $this->publish('unregistered/2026_08_11_000200_create_ranks_table.php');

        $findings = $this->audit(['2026_08_11_000200_create_ranks_table'])->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
    }

    public function test_an_unreachable_ledger_passes_with_a_stated_reason(): void
    {
        // A ledger table that is not there stands in for the whole family of "cannot reach the ledger"
        // conditions — no connection, no database, not migrated yet. A doctor runs in environments
        // without a database and must not turn that into a finding.
        config(['database.migrations.table' => 'a_ledger_table_that_does_not_exist']);

        $findings = (new LedgerAheadOfRepositoryAudit(null, [$this->root]))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_an_empty_ledger_passes(): void
    {
        $this->assertSame(DoctorStatus::Pass, $this->audit([])->run()[0]->status);
    }
}
