<?php

namespace Splicewire\Beam\Tests\Surgeon\Fixtures;

use Splicewire\Beam\Particle\Backing\BacksModel;
use Splicewire\Beam\Particle\Backing\ModelResourceIndex;
use Splicewire\Beam\Particle\Backing\ResourceBacking;

/**
 * A backing OBJECT that declares {@see BacksModel} — so the model it covers is something only
 * `modelClass()` can answer, and is NOT the string in the declaration's `backing:` slot.
 *
 * That gap is the point. A coverage audit that compared the `backing:` slot against the model census
 * — the obvious shortcut, and one that passes every test written with `backing: Model::class` — would
 * record coverage against `WidgetBacking` and report `App\Models\Widget` as unsurfaced. Only going
 * through {@see ModelResourceIndex} gets this right.
 */
class WidgetBacking implements BacksModel, ResourceBacking
{
    public function modelClass(): string
    {
        return 'App\\Models\\Widget';
    }
}
