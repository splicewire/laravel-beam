<?php

namespace Splicewire\Beam\Tests\Doctor\Fixtures;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;

/**
 * A consumer-tail registration that reports one recognisable Pass, used to prove the run REACHED the
 * tail (beam-facade ticket 90). The head audits execute before `DoctorRunner`, so an unguarded throw
 * there swallowed every registered audit behind it — and the only way to see that from a test is a
 * finding that could not have been printed by the head.
 *
 * Deliberately not a real check: its whole job is to be the last line of a completed run.
 */
class TailSentinelAudit implements DoctorAudit
{
    public const CHECK = 'fixture.tail-sentinel';

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        return [Finding::pass(self::CHECK, 'the consumer tail ran')];
    }
}
