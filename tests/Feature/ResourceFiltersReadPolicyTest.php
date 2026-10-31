<?php

namespace Splicewire\Beam\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Authorization\ResourceReadPolicy;
use Splicewire\Beam\Filters\ResourceFilters;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Tests\TestCase;

class ResourceFiltersReadPolicyTest extends TestCase
{
    public function test_a_custom_read_policy_controls_metadata_without_a_backing_view_any_policy(): void
    {
        $actor = (new User)->forceFill(['id' => 1]);
        $this->actingAs($actor);
        request()->setUserResolver(fn () => $actor);
        Gate::define('filters.test.read', fn (User $user): bool => $user->id === 1);
        $resource = new ParticleResource(
            key: 'custom-filter-read', backing: FilterPolicyRecord::class, data: Data::class,
            policy: 'filters.test.read', readPolicy: FilterMetadataReadPolicy::class,
            readBoundary: ParticleResource::READ_BOUNDARY_GLOBAL,
        );
        $this->assertNull(Gate::getPolicyFor(FilterPolicyRecord::class));
        $policy = new FilterMetadataReadPolicy;
        $this->app->instance(FilterMetadataReadPolicy::class, $policy);
        $filters = app(ResourceFilters::class);
        try {
            $filters->authorizeModel(FilterPolicyRecord::class, $resource);
            $this->fail('Filter metadata skipped the declared read refusal.');
        } catch (AuthorizationException) {
            $this->assertSame(1, $policy->calls);
        }
        $policy->allow = true;
        $filters->authorizeModel(FilterPolicyRecord::class, $resource);
        $this->assertSame(2, $policy->calls, 'The same policy also admits the positive control.');
    }
}

class FilterPolicyRecord extends Model {}

class FilterMetadataReadPolicy implements ResourceReadPolicy
{
    public bool $allow = false;

    public int $calls = 0;

    public function inspect(?Authenticatable $actor, ParticleResource $resource, Request $request): Response
    {
        $this->calls++;

        return $this->allow ? Response::allow() : Response::deny('Context required.');
    }
}
