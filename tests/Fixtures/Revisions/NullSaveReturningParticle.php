<?php

namespace Splicewire\Beam\Tests\Fixtures\Revisions;

use Splicewire\Beam\Models\BeamParticle;

/**
 * A BeamParticle whose save() PERSISTS normally but REPORTS null — the shape of a host override that
 * returns something other than a strict boolean (Eloquent's save() is untyped). A null result is NOT
 * a veto: the write stored. The revert recorder must therefore treat it as stored, not refused —
 * only a strict `false` means a halting listener refused the write.
 */
class NullSaveReturningParticle extends BeamParticle
{
    /**
     * Its OWN durable morph alias (registered to this class in the test's morph map), so a revision
     * entry recorded for this subclass resolves BACK to this subclass on revert — reaching this null-
     * reporting save(), not the base BeamParticle the inherited `beam_particle` alias would rebuild.
     */
    public function getMorphClass(): string
    {
        return 'null_save_particle';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = [])
    {
        parent::save($options);

        return null;
    }
}
