<?php

namespace Splicewire\Beam\Collections;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Rushing\LaravelDataSchemasScribe\Attributes\QueryFromData;
use Splicewire\Beam\Http\Particle\ParticleController;

/** Metadata-only specialization: every CRUD operation retains the shared resource machinery. */
#[QueryFromData(CollectionAddressData::class)]
class CollectionController extends ParticleController
{
    #[QueryFromData(CollectionAddressData::class)]
    public function index(Request $request): Responsable
    {
        return parent::index($request);
    }
}
