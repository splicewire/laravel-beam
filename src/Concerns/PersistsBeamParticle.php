<?php

namespace Splicewire\Beam\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * The `Particle` vocabulary name for {@see PersistsSchemaRecord} (beam-particle-rename ticket 01, EXPAND
 * phase). A trait cannot be `class_alias`'d, so the rename ships as a thin wrapping trait: a model may
 * `use PersistsBeamParticle` today and get exactly the `PersistsSchemaRecord` behaviour, so call sites
 * migrate at their own cadence before the contract phase (T07) makes the particle name canonical.
 *
 * @mixin Model
 */
trait PersistsBeamParticle
{
    use PersistsSchemaRecord;
}
