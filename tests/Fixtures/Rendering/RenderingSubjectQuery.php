<?php

namespace Splicewire\Beam\Tests\Fixtures\Rendering;

/** The `with(...)->findOrFail(...)` half of the duck-typed store shape the default resolver expects. */
class RenderingSubjectQuery
{
    public function findOrFail(string $id): RenderingSubject
    {
        return RenderingSubject::findOrFail($id);
    }
}
