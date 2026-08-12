<?php

namespace Splicewire\Beam\Authorization;

use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * The default {@see ActorPort}: the actor is whoever the configured Laravel guard reports.
 *
 * This class is deliberately the ONLY place in the authorization layer that touches ambient
 * authentication. Keeping that read behind the port is what lets a transport with no ambient user
 * (MCP over stdio) substitute its own actor source without the resolver — or any call site that asks
 * the resolver a question — changing at all.
 */
class GuardActorPort implements ActorPort
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
