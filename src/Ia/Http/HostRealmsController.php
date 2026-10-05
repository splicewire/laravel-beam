<?php

namespace Splicewire\Beam\Ia\Http;

use Illuminate\Http\Request;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Ia\HostIa;
use Splicewire\Beam\Ia\HostRealmsData;

/**
 * The SPA's per-request read of the host's IA (ux-walkthrough M2; OQ-5's default emission site). `?path=` is the
 * pathname the SPA is on, which decides `current` and `back`. A host mounts this controller wherever its SPA API
 * lives; it authors no realm payload of its own.
 */
final class HostRealmsController
{
    #[ResponseFromData(HostRealmsData::class)]
    public function __invoke(Request $request, HostIa $ia): HostRealmsData
    {
        $path = $request->query('path');

        return $ia->realms($request->user(), is_string($path) && $path !== '' ? $path : null);
    }
}
