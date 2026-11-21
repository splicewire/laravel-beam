<?php

namespace Splicewire\Beam\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Agreements\AssentRecorder;
use Splicewire\Beam\Write\WriteRequirement;

final class AssentRecorderSpy implements AssentRecorder
{
    /** @var list<array{subject: Model, requirement: WriteRequirement, transactionLevel: int}> */
    public array $calls = [];

    public array $order = [];

    public function record(Model $subject, WriteRequirement $requirement): void
    {
        $this->order[] = 'record';
        $this->calls[] = [
            'subject' => $subject,
            'requirement' => $requirement,
            'transactionLevel' => $subject->getConnection()->transactionLevel(),
        ];
    }
}
