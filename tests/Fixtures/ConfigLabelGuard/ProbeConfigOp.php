<?php

namespace Splicewire\Beam\Tests\Fixtures\ConfigLabelGuard;

use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Tests\Fixtures\ConfigLabelGuard\Elsewhere\ProbeScope;

/**
 * A `beam-config` op that is never booted. ConfigIdentifierLabelTest feeds it to the config guard to prove the guard
 * follows REACHABILITY, not namespace: its labelled output reaches {@see ProbeScope}, an unlabelled enum outside
 * `Splicewire\Beam\Data\Config` and `Splicewire\Beam\Enums`.
 */
#[ParticleOp(
    resource: 'beam-config',
    name: 'label-guard-probe',
    kind: OperationKind::Read,
    ability: false,
    output: ProbeLabelledData::class,
    subject: NoSubject::class,
)]
final class ProbeConfigOp
{
    public static function handle(): ProbeLabelledData
    {
        return new ProbeLabelledData(ProbeScope::Wide);
    }
}
