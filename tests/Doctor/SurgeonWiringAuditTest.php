<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\SurgeonWiringAudit;
use Splicewire\Beam\Tests\TestCase;

class SurgeonWiringAuditTest extends TestCase
{
    public function test_warns_when_surgeon_is_not_required(): void
    {
        $finding = (new SurgeonWiringAudit)->run(['require' => ['splicewire/laravel-beam' => 'dev-main']]);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('rushing/laravel-surgeon', $finding->detail);
    }

    public function test_passes_when_surgeon_is_a_top_level_require(): void
    {
        $finding = (new SurgeonWiringAudit)->run(['require' => ['rushing/laravel-surgeon' => '*']]);

        $this->assertSame(DoctorStatus::Pass, $finding->status);
    }

    public function test_passes_when_surgeon_is_only_a_dev_require(): void
    {
        $finding = (new SurgeonWiringAudit)->run(['require-dev' => ['rushing/laravel-surgeon' => '*']]);

        $this->assertSame(DoctorStatus::Pass, $finding->status);
    }

    public function test_warns_on_an_empty_composer_json(): void
    {
        $finding = (new SurgeonWiringAudit)->run([]);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
    }
}
