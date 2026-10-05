<?php

namespace Splicewire\Beam\Ia;

/**
 * The side of a cross-instance relationship a surface serves (ux-walkthrough M6, IA-6). A hub (Tower, the flagship)
 * pairs and operates other instances; a client (a satellite) connects to a hub. A host declares the sides it plays in
 * `beam.core.ia.plays`, and {@see HostIa::serve()} mounts a surface only on a host that plays its side.
 */
enum Side: string
{
    case Hub = 'hub';
    case Client = 'client';
}
