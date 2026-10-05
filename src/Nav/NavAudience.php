<?php

namespace Splicewire\Beam\Nav;

/**
 * Who a nav seat is for (ux-walkthrough IA-8, M4). The projector draws `developer` seats in the Developer zone and every
 * other seat in the rail.
 *
 * ## The read-only rule decides it
 *
 * A seat is `developer` when it is a read-only view of code-declared or derived machinery: its resource or page
 * declares no write `#[ParticleOp]`, and its rows are declared by, or derived from, code and config (registries,
 * catalogs, mirrors, schemas, retrieval layers). Anything else is `product`, including a read-only record of principals'
 * actions such as an activity log.
 *
 * There is deliberately no `admin` value: no renderer or invariant would read it, and the realm already carries the
 * operator/tenant difference.
 */
enum NavAudience: string
{
    case Product = 'product';
    case Developer = 'developer';
}
