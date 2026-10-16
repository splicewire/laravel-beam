<?php

namespace Splicewire\Beam\Tests\Webhooks;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Mockery;
use RuntimeException;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Webhooks\CreateHookSubscription;
use Splicewire\Beam\Webhooks\HookFrameResourceHandler;

class HookBoundActorTest extends TestCase
{
    public function test_hook_override_passes_bound_guest_instead_of_ambient_member(): void
    {
        $this->actingAs(new User);
        $request = Request::create('/');
        $request->setUserResolver(fn () => new User);
        $this->app->instance('request', $request);
        $this->app->instance(Request::class, $request);
        $creator = Mockery::mock(CreateHookSubscription::class);
        $creator->shouldReceive('create')->once()->withArgs(function ($input, Request $request) {
            $this->assertNull($request->user());

            return true;
        })->andThrow(new RuntimeException('creator reached'));
        $this->app->instance(CreateHookSubscription::class, $creator);
        $handler = new class extends HookFrameResourceHandler
        {
            public function __construct() {}

            protected function resource(ResourceDefinition $definition): ?ParticleResource
            {
                return null;
            }

            protected function assertWritable(ResourceDefinition $definition, string $verb): void {}
        };
        $definition = (new \ReflectionClass(ResourceDefinition::class))->newInstanceWithoutConstructor();
        $this->expectExceptionMessage('creator reached');
        $handler->forActor(null)->store($definition, []);
    }
}
