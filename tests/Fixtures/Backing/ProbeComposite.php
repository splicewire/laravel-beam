<?php

namespace Splicewire\Beam\Tests\Fixtures\Backing;

use Splicewire\Beam\Particle\Backing\CompositeBacking;

/**
 * A composite declared as a CLASS — the form a `backing:` slot takes on a `#[ParticleResource]`
 * attribute, where only a class-string can be written and the container resolves it per request.
 */
class ProbeComposite extends CompositeBacking
{
    public function __construct()
    {
        parent::__construct(sortKey: 'at');
    }

    protected function arms(): array
    {
        return [
            'alpha' => new InMemoryArm(
                new ArmRow('a1', 'alpha', 'Alpha one', 'T01'),
                new ArmRow('a5', 'alpha', 'Alpha five', 'T05'),
                new ArmRow('a3', 'alpha', 'Alpha three', 'T03'),
            ),
            'beta' => new InMemoryArm(
                new ArmRow('b4', 'beta', 'Beta four', 'T04'),
                new ArmRow('b2', 'beta', 'Beta two', 'T02'),
            ),
        ];
    }
}
