<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * The default {@see ActorPort} adapter: the actor is whoever the configured Laravel guard reports.
 *
 * This class is deliberately the ONLY place in the authorization layer that touches ambient
 * authentication. Keeping that read behind the port is what lets a transport with no ambient user
 * (MCP over stdio) substitute its own actor source without the resolver — or any call site that asks
 * the resolver a question — changing at all.
 *
 * Named `Adapter`, not `Port`, per ADR-0213: the port is the interface, the adapter is what plugs
 * into it, and an implementation never repeats its interface's suffix.
 */
class GuardActorAdapter implements ActorPort
{
    public function __construct(
        protected AuthFactory $auth,
        protected ?string $guard = null,
    ) {}

    public function actor(): mixed
    {
        return $this->auth->guard($this->guard)->user();
    }
}
