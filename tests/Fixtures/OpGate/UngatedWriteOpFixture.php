<?php

namespace Splicewire\Beam\Tests\Fixtures\OpGate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;

/**
 * **The planted defect.** A `kind: Write` declaration with no `ability:` — the exact shape
 * `Splicewire\Beam\Particle\Attributes\UngatedWriteDeclarations` exists to refuse, kept so the guard
 * is a thing that has been SEEN to fail rather than one nobody has watched go red.
 *
 * ⚠️ It lives under `tests/` on purpose. The guard's real assertion scans beam's `src/`, so a fixture
 * inside that tree would redden the suite permanently; this one is scanned only by the positive control
 * and is registered by nothing.
 */
#[ParticleOp(resource: 'op-gate-fixtures', name: 'ungated-write', kind: OperationKind::Write)]
class UngatedWriteOpFixture
{
    public static function handle(Model $model, Request $request, mixed $actor): mixed
    {
        return null;
    }
}
