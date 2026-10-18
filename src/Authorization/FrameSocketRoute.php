<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Http\Request;
use Schemastud\Frame\Http\Controllers\ServesResourceSocket;

/** Identifies the shared Frame resource socket by its serving action, never by a forgeable route name. */
final class FrameSocketRoute
{
    public static function serves(Request $request): bool
    {
        // The socket declares ITSELF (frame's ServesResourceSocket marker, A4-C4): a future socket
        // controller opts in at its declaration and this helper recognizes it with no edit — the
        // hand-maintained three-class list this replaces was duplicated here and in the audit, so a
        // new socket controller silently escaped both.
        $controller = $request->route()?->getControllerClass();

        return $controller !== null
            && is_a($controller, ServesResourceSocket::class, true);
    }
}
