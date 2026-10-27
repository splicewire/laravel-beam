<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;

/** Authored operation titles are advisory: legacy declarations retain their derived doc titles. */
class UndeclaredOperationSummaryAudit implements DoctorAudit
{
    public const CHECK = 'particle.operation-summary';

    public function __construct(protected ParticleOperationRegistry $operations) {}

    /** @return list<Finding> */
    public function run(): array
    {
        /** @var list<ParticleOperation> $all */
        $all = $this->operations->unfiltered()->matches('beam.particle.operations');
        if ($all === []) {
            return [Finding::inconclusive(self::CHECK, 'No particle operations are registered; no summary population was measured.')];
        }

        $missing = array_values(array_filter($all, fn (ParticleOperation $op): bool => trim($op->summary ?? '') === ''));
        if ($missing === []) {
            return [Finding::pass(self::CHECK, sprintf('All %d registered operations declare a summary.', count($all)))];
        }

        return [Finding::warn(self::CHECK, sprintf(
            "%d of %d registered operations declare no non-empty summary. Declare summary: on the operation; the derived title remains the fallback.\n%s",
            count($missing), count($all), implode("\n", array_map(fn (ParticleOperation $op): string => '  '.$op->key(), $missing)),
        ))];
    }
}
