<?php

namespace Splicewire\Beam\Tests\Fixtures\Revisions;

use Splicewire\Beam\Models\BeamParticle;

/**
 * A BeamParticle whose save() PERSISTS normally but REPORTS the integer `0` — a host-override shape
 * (Eloquent's save() is untyped) that returns a FALSEY NON-BOOL for a write that stored. Because this
 * package has no `declare(strict_types=1)`, a `?bool` outcome seam would coerce that `0` to `false` and
 * refuse the stored write; the seam takes `mixed` and keys refusal on a strict `=== false`, so `0` is
 * read as stored. The base restoreVersionSnapshot() reports `$this->save()` through that seam, so this
 * subclass needs only to override save() — the inherited restore reports its `0` and the revert must be
 * recorded, not refused. Guards the M1/F4 strict-false rule.
 */
class ZeroSaveReturningParticle extends BeamParticle
{
    public function getMorphClass(): string
    {
        return 'zero_save_particle';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = [])
    {
        parent::save($options);

        return 0;
    }
}
