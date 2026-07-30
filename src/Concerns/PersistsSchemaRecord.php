<?php

namespace Splicewire\Beam\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * RETIRED trait name, kept as a THIN back-compat shim (beam-particle-rename ticket 07, CONTRACT phase).
 * The canonical trait is now {@see PersistsBeamParticle}; a trait cannot be `class_alias`'d, so the
 * retired `PersistsSchemaRecord` name ships as this composing trait rather than an alias.
 *
 * An external consumer still `use`-ing `PersistsSchemaRecord` gets exactly the canonical behaviour —
 * Laravel's `bootTraits`/`initializeTraits` walks `class_uses_recursive`, so the composed
 * {@see PersistsBeamParticle::initializePersistsBeamParticle()} still fires. Deleted once the external
 * window closes (T09+).
 *
 * @mixin Model
 */
trait PersistsSchemaRecord
{
    use PersistsBeamParticle;
}
