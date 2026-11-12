<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\ParticleResource;

/** A backing supplies the projection input for its declared Data shape. */
interface ProjectsPayload extends ResourceBacking
{
    public static function project(ParticleResource $resource, Model $record, ?string $data = null): Data;
}
