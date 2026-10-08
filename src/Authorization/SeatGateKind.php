<?php

namespace Splicewire\Beam\Authorization;

/** The one authorization vocabulary a projected seat resolved from its named route. */
enum SeatGateKind: string
{
    case Resource = 'resource';
    case Operation = 'operation';
    case Route = 'route';
    case Open = 'open';
}
