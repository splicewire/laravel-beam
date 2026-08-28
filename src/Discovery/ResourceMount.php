<?php

namespace Splicewire\Beam\Discovery;

use Illuminate\Routing\Route;

/**
 * One MOUNT of one resource — the unit the discovery listing is published per (41 D5).
 *
 * Per-mount, not per-resource, and that is stated out loud because it is a real property of the design
 * rather than an accident: `hooks` is live on both `/api/v1` and `/api/operator`, so it gets two
 * listings, each showing that mount's reach. An operator-tier listing that reported the tenant tier's
 * routes would be answering a question nobody asked.
 */
class ResourceMount
{
    /**
     * @param  string  $resource  The `_particle` stamp every route in this mount resolves to.
     * @param  string  $root  The URI prefix the mount hangs off — `api/v1/circuits`, or
     *                        `api/v1/circuits/{circuit}/guest-tokens` for a bound relative.
     * @param  string  $nameStem  The route-name stem the listing's own route takes.
     * @param  list<Route>  $routes  Every stamped route under this root, in route-table order.
     * @param  list<string>  $middleware  Middleware common to EVERY route in the mount.
     */
    public function __construct(
        public string $resource,
        public string $root,
        public string $nameStem,
        public array $routes = [],
        public array $middleware = [],
    ) {}

    public function uri(): string
    {
        return $this->root === '' ? 'discovery' : $this->root.'/discovery';
    }

    public function routeName(): string
    {
        return $this->nameStem === '' ? 'discovery' : $this->nameStem.'.discovery';
    }
}
