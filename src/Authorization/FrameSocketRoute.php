<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Http\Request;
use Schemastud\Frame\Http\Controllers\FrameResourceController;
use Schemastud\Frame\Http\Controllers\FrameResourceFiltersController;
use Schemastud\Frame\Http\Controllers\FrameResourceSummaryController;

/** Identifies the shared Frame resource socket by its serving action, never by a forgeable route name. */
final class FrameSocketRoute
{
    public static function serves(Request $request): bool
    {
        return in_array($request->route()?->getControllerClass(), [
            FrameResourceController::class,
            FrameResourceFiltersController::class,
            FrameResourceSummaryController::class,
        ], true);
    }
}
