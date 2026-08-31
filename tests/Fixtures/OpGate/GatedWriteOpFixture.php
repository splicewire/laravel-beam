<?php

namespace Splicewire\Beam\Tests\Fixtures\OpGate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/**
 * A declared token — the ordinary, correct shape.
 *
 * One of the three declarations the guard must NOT report — see {@see UngatedWriteOpFixture} for the
 * one it must. They sit together so the positive control asserts a SET rather than a count.
 */
#[ParticleOp(resource: 'op-gate-fixtures', name: 'gated-write', kind: OperationKind::Write, ability: 'update')]
class GatedWriteOpFixture
{
    public static function handle(Model $model, Request $request, mixed $actor): mixed
    {
        return null;
    }
}
