<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Filesystem\Filesystem;
use Rushing\Doctor\DoctorRunner;
use Rushing\Doctor\DoctorStatus;
use Schemastud\Frame\Wire\EmittedWireKeys;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\FramedDeclarationAudit;
use Splicewire\Beam\Doctor\FrameWireKeysAudit;
use Splicewire\Beam\Tests\TestCase;

class FrameWireKeysAuditTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/beam-wire-keys-'.uniqid();
        (new Filesystem)->ensureDirectoryExists($this->directory);
        config(['frame.vocabulary' => $this->directory.'/vocabulary.json']);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function vocabulary(array $data): void
    {
        file_put_contents(config('frame.vocabulary'), json_encode(['version' => 1, ...$data], JSON_THROW_ON_ERROR));
    }

    public function test_one_missing_client_key_is_one_finding_naming_the_key(): void
    {
        $keys = EmittedWireKeys::clientKeys();
        $missing = array_shift($keys);
        $this->vocabulary(['reads' => $keys]);
        $findings = app(FrameWireKeysAudit::class)->run();
        $this->assertCount(1, $findings);
        $this->assertSame('frame.wire-keys', $findings[0]->check);
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString($missing, $findings[0]->detail);
    }

    public function test_present_empty_reads_is_compared_instead_of_inconclusive(): void
    {
        $this->vocabulary(['reads' => []]);
        $findings = app(FrameWireKeysAudit::class)->run();
        $this->assertCount(count(EmittedWireKeys::clientKeys()), $findings);
        foreach ($findings as $finding) {
            $this->assertSame(DoctorStatus::Fail, $finding->status);
            $this->assertTrue($finding->conclusive);
        }
    }

    public function test_order_duplicates_and_extra_reader_keys_do_not_create_findings(): void
    {
        $keys = EmittedWireKeys::clientKeys();
        $this->vocabulary(['reads' => ['manifest:host-only', ...array_reverse($keys), ...$keys]]);
        $findings = app(FrameWireKeysAudit::class)->run();
        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_no_reads_is_inconclusive_and_names_the_generation_command(): void
    {
        $this->vocabulary([]);
        $findings = app(FrameWireKeysAudit::class)->run();
        $this->assertCount(1, $findings);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('pnpm frame:vocabulary --write', $findings[0]->detail);
    }

    public function test_missing_and_unreadable_files_match_the_declaration_audits_inconclusive_words(): void
    {
        foreach ([null, 'not json'] as $contents) {
            if ($contents !== null) {
                file_put_contents(config('frame.vocabulary'), $contents);
            }
            $findings = app(FrameWireKeysAudit::class)->run();
            $other = app(FramedDeclarationAudit::class)->run();
            $this->assertCount(1, $findings);
            $this->assertFalse($findings[0]->conclusive);
            $this->assertSame($other[0]->detail, $findings[0]->detail);
        }
    }

    public function test_the_registered_audit_is_advisory_and_runs_through_doctor(): void
    {
        $this->vocabulary(['reads' => EmittedWireKeys::clientKeys()]);
        $registrations = array_values(array_filter(app(BeamDoctorManifest::class)->registrations(), fn ($r) => $r->audit === FrameWireKeysAudit::class));
        $this->assertCount(1, $registrations);
        $this->assertFalse($registrations[0]->gate);
        $report = app(DoctorRunner::class)->run($registrations, DoctorStatus::Warn);
        $this->assertCount(1, $report->findings);
        $this->assertSame(DoctorStatus::Pass, $report->findings[0]->status);
    }
}
