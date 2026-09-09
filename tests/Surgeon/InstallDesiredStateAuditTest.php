<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Surgeon\InstallDesiredStateAudit;

/**
 * prove-the-beam-starter 12 — `beam.install.desired-state`. Constructed directly from an injected
 * observation, no container and no filesystem, mirroring {@see ClientRuntimeContractAuditTest}.
 *
 * The point of this file is the THREE-WAY read, not coverage. A doctor audit encoding "the install
 * worked" is exactly where a pass-by-not-looking would hide, so every case asserts the `conclusive`
 * flag as well as the status: a requirement the audit could not measure must not be spelled the same
 * as one it measured and found satisfied.
 *
 * The two trees this was validated in (a clean-room install, and `~/Herd/beam`) both read satisfied,
 * so the RED half of the acceptance is observed here — deterministically, and without manufacturing a
 * defect in anyone's working tree.
 */
class InstallDesiredStateAuditTest extends TestCase
{
    private const HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * @param  array<string, bool|null>  $residues
     * @param  array<string, string|null>  $docs
     * @param  list<string>  $frameMiddleware
     * @param  list<string>  $outsideTreeDeps
     * @param  array{exit?: int|string, at?: string, commit?: string}|null  $ciRecord
     */
    private function audit(
        array $residues = ['.env' => true, 'app key' => true, 'node_modules/' => true],
        ?array $ciRecord = null,
        ?string $rootAction = 'App\Http\Controllers\HomeController@index',
        bool $rootCatchAll = false,
        array $docs = ['' => 'resolved', 'docs' => 'resolved', 'docs/api' => 'resolved', 'docs/mcp' => 'resolved'],
        ?string $frameUri = 'frame/resources/{resource}',
        array $frameMiddleware = ['web', 'auth'],
        bool $lockfilePresent = true,
        array $outsideTreeDeps = [],
        bool $nodeModulesPresent = true,
    ): InstallDesiredStateAudit {
        return new InstallDesiredStateAudit(
            setupSteps: ['composer install', '@php artisan key:generate'],
            setupResidues: $residues,
            ciCheckRecord: $ciRecord,
            ciCheckRecordPath: '/host/storage/app/beam/ci-check.json',
            headCommit: self::HEAD,
            rootRouteAction: $rootAction,
            rootServedByEntryCatchAll: $rootCatchAll,
            docsResolution: $docs,
            frameProbePath: '/frame/resources/tokens',
            frameRouteUri: $frameUri,
            frameRouteMiddleware: $frameMiddleware,
            lockfilePresent: $lockfilePresent,
            outsideTreeDeps: $outsideTreeDeps,
            nodeModulesPresent: $nodeModulesPresent,
            figures: ['routes registered' => 78, 'tables' => '41 (sqlite)'],
        );
    }

    /** @return array<string, Finding> */
    private function byCheck(InstallDesiredStateAudit $audit): array
    {
        $out = [];

        foreach ($audit->run() as $finding) {
            $out[$finding->check] = $finding;
        }

        return $out;
    }

    public function test_every_requirement_reports_exactly_once_and_nothing_ever_fails(): void
    {
        $findings = $this->audit()->run();

        $this->assertCount(8, $findings);

        foreach ($findings as $finding) {
            $this->assertNotSame(DoctorStatus::Fail, $finding->status, 'Every requirement is a fact about a HOST, so this audit may never gate.');
        }
    }

    /* ------------------------------------------------------------------ R1 */

    public function test_r1_is_inconclusive_when_every_residue_is_present(): void
    {
        $r1 = $this->byCheck($this->audit())[InstallDesiredStateAudit::CHECK_R1];

        $this->assertSame(DoctorStatus::Pass, $r1->status);
        $this->assertFalse($r1->conclusive, 'A finished process\'s exit status is not observable from a booted app.');
        $this->assertStringContainsString('failed on its LAST step', $r1->detail);
    }

    public function test_r1_warns_conclusively_on_a_missing_residue(): void
    {
        $r1 = $this->byCheck($this->audit(residues: ['.env' => true, 'node_modules/' => false]))[InstallDesiredStateAudit::CHECK_R1];

        $this->assertSame(DoctorStatus::Warn, $r1->status);
        $this->assertTrue($r1->conclusive);
        $this->assertStringContainsString('node_modules/', $r1->detail);
    }

    public function test_r1_reads_its_step_count_from_the_host_rather_than_hard_coding_seven(): void
    {
        $this->assertStringContainsString('2 step(s)', $this->byCheck($this->audit())[InstallDesiredStateAudit::CHECK_R1]->detail);
    }

    /* ------------------------------------------------------------------ R2 */

    public function test_r2_is_always_inconclusive_because_it_is_inside_the_run_it_would_count(): void
    {
        $r2 = $this->byCheck($this->audit())[InstallDesiredStateAudit::CHECK_R2];

        $this->assertSame(DoctorStatus::Pass, $r2->status);
        $this->assertFalse($r2->conclusive);
        $this->assertStringContainsString('incomplete by construction', $r2->detail);
        // The extractor travels with the finding, so the operator is not left to invent one.
        $this->assertStringContainsString('grep -c', $r2->detail);
        // 01 recorded 194 WARN; it is not reproducible and is deliberately not encoded.
        $this->assertStringNotContainsString('194', $r2->detail);
        $this->assertStringContainsString('0 / 153 / 99', $r2->detail);
    }

    /* ------------------------------------------------------------------ R3 */

    public function test_r3_is_inconclusive_with_no_record_and_never_shells_out(): void
    {
        $r3 = $this->byCheck($this->audit())[InstallDesiredStateAudit::CHECK_R3];

        $this->assertSame(DoctorStatus::Pass, $r3->status);
        $this->assertFalse($r3->conclusive);
        $this->assertStringContainsString('NOT RUN', $r3->detail);
    }

    public function test_r3_reports_a_recorded_pass_as_recorded_never_as_fresh(): void
    {
        $r3 = $this->byCheck($this->audit(ciRecord: ['exit' => 0, 'at' => '2026-09-08T10:00:00Z', 'commit' => self::HEAD]))[InstallDesiredStateAudit::CHECK_R3];

        $this->assertSame(DoctorStatus::Pass, $r3->status);
        $this->assertTrue($r3->conclusive);
        $this->assertStringContainsString('RECORDED pass', $r3->detail);
        $this->assertStringContainsString('did not re-run it', $r3->detail);
    }

    public function test_r3_warns_when_the_recorded_pass_is_from_another_commit(): void
    {
        $r3 = $this->byCheck($this->audit(ciRecord: ['exit' => 0, 'at' => '2026-09-08T10:00:00Z', 'commit' => str_repeat('b', 40)]))[InstallDesiredStateAudit::CHECK_R3];

        $this->assertSame(DoctorStatus::Warn, $r3->status, 'A stale pass and a fresh pass must not be spelled the same.');
        $this->assertStringContainsString('STALE', $r3->detail);
    }

    public function test_r3_distinguishes_a_verdict_from_an_unmeasured_gate(): void
    {
        $one = $this->byCheck($this->audit(ciRecord: ['exit' => 1, 'commit' => self::HEAD]))[InstallDesiredStateAudit::CHECK_R3];
        $two = $this->byCheck($this->audit(ciRecord: ['exit' => 2, 'commit' => self::HEAD]))[InstallDesiredStateAudit::CHECK_R3];

        $this->assertSame(DoctorStatus::Warn, $one->status);
        $this->assertSame(DoctorStatus::Warn, $two->status);
        $this->assertStringContainsString('a verdict', $one->detail);
        $this->assertStringContainsString('UNMEASURED', $two->detail);
    }

    /**
     * `bin/ci-check` is a THREE-state runner and this is the only reader of its record, so exit 2 has to
     * survive the round trip as itself. Both 1 and 2 are a Warn — neither is a pass — but they are
     * different faults and must not be spelled the same: 1 means a gate ran and failed, 2 means a gate
     * never reported. Telling an operator to go read a failing gate that does not exist is the exact
     * substitution the runner's UNSPAWNED/TRUNCATED split exists to prevent, one repository over.
     */
    public function test_r3_never_calls_the_unmeasured_state_a_verdict(): void
    {
        $one = $this->byCheck($this->audit(ciRecord: ['exit' => 1, 'commit' => self::HEAD]))[InstallDesiredStateAudit::CHECK_R3];
        $two = $this->byCheck($this->audit(ciRecord: ['exit' => 2, 'commit' => self::HEAD]))[InstallDesiredStateAudit::CHECK_R3];

        $this->assertTrue($one->conclusive, 'A recorded failure against this HEAD is measured.');
        $this->assertTrue($two->conclusive);

        $this->assertStringContainsString('exit 1', $one->detail);
        $this->assertStringContainsString('exit 2', $two->detail);
        $this->assertStringContainsString('not a verdict', $two->detail);
        $this->assertStringNotContainsString('not a verdict', $one->detail);
        $this->assertNotSame($one->detail, $two->detail);
    }

    /**
     * The `exit` a runner writes through `json_encode` is an int; a hand-edited or half-written record
     * can carry anything. `is_numeric` is what separates "the record says something" from "the record
     * says nothing", and a record that says nothing is not a pass.
     */
    public function test_r3_warns_on_a_record_that_says_nothing(): void
    {
        foreach ([['at' => 'now', 'commit' => self::HEAD], ['exit' => 'green', 'commit' => self::HEAD], ['exit' => null]] as $malformed) {
            /** @var array{exit?: int|string, at?: string, commit?: string} $malformed */
            $r3 = $this->byCheck($this->audit(ciRecord: $malformed))[InstallDesiredStateAudit::CHECK_R3];

            $this->assertSame(DoctorStatus::Warn, $r3->status);
            $this->assertStringContainsString('says nothing', $r3->detail);
            $this->assertStringContainsString('not a pass', $r3->detail);
        }
    }

    /**
     * A record with no `commit` is unattributable, not fresh. The runner writes `null` there rather
     * than guessing when it cannot read `.git`, so this is a shape the estate actually produces —
     * a `--no-dev` container, a tarball deploy, a worktree without `.git`.
     */
    public function test_r3_warns_on_a_record_that_names_no_commit(): void
    {
        $r3 = $this->byCheck($this->audit(ciRecord: ['exit' => 0, 'at' => '2026-09-09T10:00:00Z']))[InstallDesiredStateAudit::CHECK_R3];

        $this->assertSame(DoctorStatus::Warn, $r3->status);
        $this->assertStringContainsString('STALE or unattributable', $r3->detail);
        $this->assertStringContainsString('(none)', $r3->detail);
    }

    /**
     * The mirror case, and the one that decides an ambiguity: the record names a commit and the AUDIT
     * cannot read this tree's HEAD. That is not evidence the record is stale — it is evidence nothing
     * can be compared — and it still may not read as a pass, because "recorded" is the only spelling
     * this audit has and an unattributable pass is the thing it refuses to launder.
     */
    public function test_r3_warns_when_this_tree_has_no_readable_head_to_compare_against(): void
    {
        $audit = new InstallDesiredStateAudit(
            setupSteps: [],
            setupResidues: [],
            ciCheckRecord: ['exit' => 0, 'at' => '2026-09-09T10:00:00Z', 'commit' => self::HEAD],
            ciCheckRecordPath: '/host/storage/app/beam/ci-check.json',
            headCommit: null,
            rootRouteAction: null,
            rootServedByEntryCatchAll: false,
            docsResolution: [],
            frameProbePath: null,
            frameRouteUri: null,
            frameRouteMiddleware: [],
            lockfilePresent: true,
            outsideTreeDeps: [],
            nodeModulesPresent: true,
            figures: [],
        );

        $r3 = $this->byCheck($audit)[InstallDesiredStateAudit::CHECK_R3];

        $this->assertSame(DoctorStatus::Warn, $r3->status);
        $this->assertStringContainsString('(unreadable)', $r3->detail);
    }

    /**
     * Every record-reading branch names the path it read, because an operator whose record is not being
     * found needs to know where the audit looked — the runner writes to its own default and a host that
     * repoints the config key has to repoint the runner too.
     */
    public function test_every_r3_reading_names_the_path_it_read(): void
    {
        $records = [
            null,
            ['exit' => 0, 'commit' => self::HEAD],
            ['exit' => 2, 'commit' => self::HEAD],
            ['exit' => 0, 'commit' => str_repeat('b', 40)],
            ['at' => 'now'],
        ];

        foreach ($records as $record) {
            /** @var array{exit?: int|string, at?: string, commit?: string}|null $record */
            $r3 = $this->byCheck($this->audit(ciRecord: $record))[InstallDesiredStateAudit::CHECK_R3];

            $this->assertStringContainsString('/host/storage/app/beam/ci-check.json', $r3->detail);
        }
    }

    /* ------------------------------------------------------------------ R4 */

    public function test_r4_is_inconclusive_for_a_host_owned_root_handler(): void
    {
        $r4 = $this->byCheck($this->audit())[InstallDesiredStateAudit::CHECK_R4];

        $this->assertFalse($r4->conclusive);
        $this->assertStringContainsString('not sufficient', $r4->detail);
    }

    public function test_r4_warns_when_no_route_matches_the_root(): void
    {
        $r4 = $this->byCheck($this->audit(rootAction: null))[InstallDesiredStateAudit::CHECK_R4];

        $this->assertSame(DoctorStatus::Warn, $r4->status);
        $this->assertTrue($r4->conclusive);
    }

    public function test_r4_becomes_conclusive_when_the_root_is_served_by_the_entry_catch_all(): void
    {
        $ok = $this->byCheck($this->audit(rootAction: InstallDesiredStateAudit::ENTRY_CONTROLLER, rootCatchAll: true))[InstallDesiredStateAudit::CHECK_R4];

        $this->assertSame(DoctorStatus::Pass, $ok->status);
        $this->assertTrue($ok->conclusive);

        $bad = $this->byCheck($this->audit(
            rootAction: InstallDesiredStateAudit::ENTRY_CONTROLLER,
            rootCatchAll: true,
            docs: ['' => 'unresolved', 'docs' => 'resolved'],
        ))[InstallDesiredStateAudit::CHECK_R4];

        $this->assertSame(DoctorStatus::Warn, $bad->status);
    }

    /* ------------------------------------------------------------------ R5 */

    public function test_r5_passes_conclusively_when_every_docs_path_resolves_for_a_guest(): void
    {
        $r5 = $this->byCheck($this->audit())[InstallDesiredStateAudit::CHECK_R5];

        $this->assertSame(DoctorStatus::Pass, $r5->status);
        $this->assertTrue($r5->conclusive);
        // The trap it exists to avoid is named in the finding itself.
        $this->assertStringContainsString('catch-all', $r5->detail);
    }

    public function test_r5_warns_when_a_docs_path_does_not_resolve(): void
    {
        $r5 = $this->byCheck($this->audit(docs: ['' => 'resolved', 'docs' => 'resolved', 'docs/mcp' => 'unresolved']))[InstallDesiredStateAudit::CHECK_R5];

        $this->assertSame(DoctorStatus::Warn, $r5->status);
        $this->assertTrue($r5->conclusive);
        $this->assertStringContainsString('`/docs/mcp`', $r5->detail);
    }

    public function test_r5_is_inconclusive_when_the_resolver_could_not_be_reached(): void
    {
        $r5 = $this->byCheck($this->audit(docs: ['' => null, 'docs' => null, 'docs/api' => null]))[InstallDesiredStateAudit::CHECK_R5];

        $this->assertSame(DoctorStatus::Pass, $r5->status);
        $this->assertFalse($r5->conclusive, 'An unreachable resolver must not read as a clean one.');
    }

    /* ------------------------------------------------------------------ R6 */

    public function test_r6_passes_conclusively_behind_auth_middleware(): void
    {
        $r6 = $this->byCheck($this->audit())[InstallDesiredStateAudit::CHECK_R6];

        $this->assertSame(DoctorStatus::Pass, $r6->status);
        $this->assertTrue($r6->conclusive);
        $this->assertStringContainsString('BEFORE the controller', $r6->detail);
    }

    public function test_r6_is_inconclusive_when_the_surface_is_ungated(): void
    {
        $r6 = $this->byCheck($this->audit(frameMiddleware: ['web']))[InstallDesiredStateAudit::CHECK_R6];

        $this->assertFalse($r6->conclusive, 'With no guard a guest reaches the controller, so the outcome is live.');
    }

    public function test_r6_warns_when_the_frame_surface_is_not_mounted(): void
    {
        $r6 = $this->byCheck($this->audit(frameUri: null, frameMiddleware: []))[InstallDesiredStateAudit::CHECK_R6];

        $this->assertSame(DoctorStatus::Warn, $r6->status);
        $this->assertTrue($r6->conclusive);
    }

    /* ------------------------------------------------------------------ R7 */

    public function test_r7_warns_without_a_lockfile(): void
    {
        $r7 = $this->byCheck($this->audit(lockfilePresent: false))[InstallDesiredStateAudit::CHECK_R7];

        $this->assertSame(DoctorStatus::Warn, $r7->status);
        $this->assertTrue($r7->conclusive);
    }

    public function test_r7_warns_on_a_dependency_that_reaches_outside_the_tree(): void
    {
        $r7 = $this->byCheck($this->audit(outsideTreeDeps: ['dependencies.@splicewire/beam-ux = workspace:*']))[InstallDesiredStateAudit::CHECK_R7];

        $this->assertSame(DoctorStatus::Warn, $r7->status);
        $this->assertStringContainsString('workspace:*', $r7->detail);
    }

    public function test_r7_is_inconclusive_when_only_the_declaration_half_is_measurable(): void
    {
        $warm = $this->byCheck($this->audit())[InstallDesiredStateAudit::CHECK_R7];

        $this->assertSame(DoctorStatus::Pass, $warm->status);
        $this->assertFalse($warm->conclusive);
        // 01's amendment: from a warm tree the command passes by skipping, so the finding says so.
        $this->assertStringContainsString('passes by SKIPPING', $warm->detail);
        $this->assertStringContainsString('this tree is warm', $warm->detail);

        $cold = $this->byCheck($this->audit(nodeModulesPresent: false))[InstallDesiredStateAudit::CHECK_R7];
        $this->assertStringContainsString('this tree is cold', $cold->detail);
    }

    /* ------------------------------------------------------------- recorded */

    public function test_recorded_figures_are_figures_with_extractors_and_no_threshold(): void
    {
        $recorded = $this->byCheck($this->audit())[InstallDesiredStateAudit::CHECK_RECORDED];

        $this->assertSame(DoctorStatus::Pass, $recorded->status);
        $this->assertStringContainsString('here 78 / clean-room 78', $recorded->detail);
        $this->assertStringContainsString('here 41 (sqlite) / clean-room 41', $recorded->detail);
        // A figure this host did not measure says so rather than printing 0.
        $this->assertStringContainsString('suite: here not measured', $recorded->detail);
        $this->assertStringContainsString('gating nothing', $recorded->detail);

        foreach (InstallDesiredStateAudit::BASELINE as $baseline) {
            $this->assertStringContainsString($baseline['extractor'], $recorded->detail);
        }
    }

    public function test_a_shrunken_figure_is_visible_and_still_passes(): void
    {
        $findings = (new \ReflectionClass(InstallDesiredStateAudit::class));
        $this->assertTrue($findings->hasConstant('BASELINE'));

        // No branch anywhere compares a live figure against a baseline — ruling 4 forbids a floor, and a
        // bound above both the defect and the fix passes either way. The guard is that the comparison
        // does not exist in code, so this asserts on the source rather than on a behaviour.
        $source = (string) file_get_contents((string) $findings->getFileName());
        $this->assertStringNotContainsString('assertLessThan', $source);
        $this->assertMatchesRegularExpression('/no floor/', $source);
    }
}
