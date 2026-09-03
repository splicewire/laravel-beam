<?php

namespace Splicewire\Beam\Tests\Fixtures\ReadGuard;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Models\BeamParticle;

/**
 * A model NOTHING in the harness binds a policy for — the policy-less half of the shape
 * beam-docs-satellite 65 rules on. {@see BeamParticle} would not do: whether it
 * carries a policy is a fact about the provider under test, not about this fixture.
 */
class Gadget extends Model
{
    protected $table = 'gadgets';

    protected $guarded = [];

    public $timestamps = false;
}
