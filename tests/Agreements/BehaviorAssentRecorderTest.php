<?php

namespace Splicewire\Beam\Tests\Agreements;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Agreements\BehaviorAssentRecorder;
use Splicewire\Beam\Agreements\Data\AgreementMemberData;
use Splicewire\Beam\Agreements\NullBehaviorAssentRecorder;
use Splicewire\Beam\Tests\TestCase;

class BehaviorAssentRecorderTest extends TestCase
{
    public function test_the_core_default_is_no_op_when_the_agreements_seam_is_absent(): void
    {
        $recorder = $this->app->make(BehaviorAssentRecorder::class);

        $this->assertInstanceOf(NullBehaviorAssentRecorder::class, $recorder);
        $this->assertSame([], $recorder->record(
            'plans.checkout',
            [new AgreementMemberData('plan-studio', 3, 'frozen-digest')],
            new class extends Model {},
        ));
    }
}
