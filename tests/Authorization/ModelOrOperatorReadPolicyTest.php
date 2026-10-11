<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Schemastud\Frame\Http\Controllers\FrameResourceController;
use Splicewire\Beam\Authorization\ModelOrOperatorReadPolicy;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

class ModelOrOperatorReadPolicyTest extends TestCase
{
    public function test_hooks_declare_the_shared_model_or_operator_read_policy(): void
    {
        $resource = app(ParticleResourceRegistry::class)->get('hooks');
        $this->assertSame(HookData::class, $resource->data);
        $this->assertSame(ModelOrOperatorReadPolicy::class, $resource->readPolicy);
    }

    public function test_operator_entitlement_requires_this_requests_operator_mount(): void
    {
        $resource = app(ParticleResourceRegistry::class)->get('hooks');
        app(ParticleResourceRegistry::class)->loadRealmMap(['operator' => ['hooks'], 'tenant' => ['hooks']]);
        Gate::policy(Hook::class, ModelOrOperatorFixturePolicy::class);

        $operator = (new ModelOrOperatorActor)->forceFill(['id' => 1]);
        $operator->operator = true;
        Gate::define('entitlement:os.operate', fn (ModelOrOperatorActor $actor): bool => $actor->operator);

        $policy = app(ModelOrOperatorReadPolicy::class);

        $this->assertTrue($policy->inspect($operator, $resource, $this->requestForRealm('operator'))->allowed());
        $this->assertTrue($policy->inspect($operator, $resource, $this->requestForRealm(null, FrameResourceController::class))->denied());
    }

    public function test_model_readers_and_operator_mounts_are_independent_authority_arms(): void
    {
        $resource = app(ParticleResourceRegistry::class)->get('hooks');
        app(ParticleResourceRegistry::class)->loadRealmMap(['operator' => ['hooks'], 'tenant' => ['hooks']]);
        Gate::policy(Hook::class, ModelOrOperatorFixturePolicy::class);

        $member = (new ModelOrOperatorActor)->forceFill(['id' => 1]);
        $member->modelReader = true;
        $operator = (new ModelOrOperatorActor)->forceFill(['id' => 2]);
        $operator->operator = true;
        $stranger = (new ModelOrOperatorActor)->forceFill(['id' => 3]);
        Gate::define('entitlement:os.operate', fn (ModelOrOperatorActor $actor): bool => $actor->operator);

        $policy = app(ModelOrOperatorReadPolicy::class);

        $this->assertTrue($policy->inspect($member, $resource, $this->requestForRealm('tenant'))->allowed());
        $this->assertTrue($policy->inspect($member, $resource, $this->requestForRealm('operator'))->allowed());
        $this->assertTrue($policy->inspect($operator, $resource, $this->requestForRealm(null, ModelOrOperatorHostController::class))->denied());
        $this->assertTrue($policy->inspect($operator, $resource, $this->requestForRealm('tenant'))->denied());
        $this->assertTrue($policy->inspect($stranger, $resource, $this->requestForRealm('operator'))->denied());
    }

    private function requestForRealm(?string $realm, ?string $controller = null): Request
    {
        $request = Request::create('/');
        $route = new Route('GET', '/', $controller === null ? fn () => null : [$controller, 'index']);
        if ($realm !== null) {
            $route->defaults('realm', $realm);
        }
        $request->setRouteResolver(fn () => $route);

        return $request;
    }
}

class ModelOrOperatorFixturePolicy
{
    public function viewAny(ModelOrOperatorActor $actor): bool
    {
        return $actor->modelReader;
    }
}

class ModelOrOperatorActor extends User
{
    public bool $modelReader = false;

    public bool $operator = false;
}

class ModelOrOperatorHostController
{
    public function index(): void {}
}
