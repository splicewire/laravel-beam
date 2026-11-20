<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Rushing\PermissionCascade\Authorization\CredentialScopeGate;
use Rushing\PermissionCascade\Authorization\CredentialScopeRefusal;
use Rushing\PermissionCascade\Authorization\CredentialScopeRefused;
use Rushing\PermissionCascade\Authorization\DecisionNames;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Authorization\ResourceReadGuard;
use Splicewire\Beam\Authorization\ResourceReadPolicy;
use Splicewire\Beam\Filters\ResourceFilters;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * Fleet ADR 0006 rule 1 (Option 3: reads fail closed too) at beam's read boundary (credential-scope 07 R1, R5): a read
 * the {@see ResourceReadGuard} admits WITHOUT a narrowed Gate decision (a `ResourceReadPolicy`, a tenancy or row scope,
 * a hard realm entitlement, an `entitlement:` read gate) asks a scoped token for the resource's read name; a read
 * admitted by a narrowed Gate decision (a declared Gate ability, `viewAny`) is already named and asks nothing more. A
 * session, a `*` token and another principal are unchanged; the principal half still refuses whatever the token carries.
 */
class ReadsAskANameTest extends TestCase
{
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = (new User)->forceFill(['id' => 7]);
        $this->actingAs($this->actor);
        request()->setUserResolver(fn () => $this->actor);
    }

    /** @param  list<string>|null  $scope */
    private function scopeTo(?array $scope): void
    {
        $this->app->instance(CredentialScopeResolver::class, new ReadsFixedScope($scope));
    }

    private function readPolicyResource(?string $policy = null): ParticleResource
    {
        return new ParticleResource(
            key: 'scoped-reports', backing: ReadsRecord::class, data: Data::class,
            policy: $policy, readPolicy: ReadsReadPolicy::class,
            readBoundary: ParticleResource::READ_BOUNDARY_GLOBAL,
        );
    }

    private function guard(): ResourceReadGuard
    {
        return ResourceReadGuard::forApp();
    }

    public function test_a_read_policy_admission_asks_a_scoped_token_for_the_resources_read_name(): void
    {
        $this->app->instance(ReadsReadPolicy::class, new ReadsReadPolicy(true));
        $resource = $this->readPolicyResource();

        $this->scopeTo(['entity.view']);
        $refused = $this->guard()->inspectReadFor($resource, request(), $this->actor);
        $this->assertTrue($refused->denied());
        $this->assertInstanceOf(CredentialScopeRefusal::class, $refused);
        $this->assertSame(CredentialScopeRefusal::NOT_CARRIED, $refused->errorCode());

        $this->scopeTo(['scoped-reports.view']);
        $this->assertTrue($this->guard()->inspectReadFor($resource, request(), $this->actor)->allowed());

        // the model's derived read name admits it too
        $this->scopeTo(['reads-record.view']);
        $this->assertTrue($this->guard()->inspectReadFor($resource, request(), $this->actor)->allowed());

        // a session or a `*` token: unchanged
        $this->scopeTo(null);
        $this->assertTrue($this->guard()->inspectReadFor($resource, request(), $this->actor)->allowed());
    }

    public function test_the_principal_half_still_refuses_whatever_the_token_carries(): void
    {
        $this->app->instance(ReadsReadPolicy::class, new ReadsReadPolicy(false));
        $this->scopeTo(['scoped-reports.view']);

        $verdict = $this->guard()->inspectReadFor($this->readPolicyResource(), request(), $this->actor);

        $this->assertTrue($verdict->denied());
        $this->assertNotInstanceOf(CredentialScopeRefusal::class, $verdict);
    }

    public function test_another_principals_read_is_not_narrowed_by_the_acting_token(): void
    {
        $this->app->instance(ReadsReadPolicy::class, new ReadsReadPolicy(true));
        $this->scopeTo(['entity.view']);
        $other = (new User)->forceFill(['id' => 8]);

        $this->assertTrue($this->guard()->inspectReadFor($this->readPolicyResource(), request(), $other)->allowed());
    }

    public function test_a_declared_gate_ability_already_names_the_read(): void
    {
        $this->app->instance(ReadsReadPolicy::class, new ReadsReadPolicy(true));
        Gate::define('reports.read', fn (?User $user = null) => $user !== null);
        $resource = $this->readPolicyResource('reports.read');

        $this->scopeTo(['reports.read']);
        $this->assertTrue($this->guard()->inspectReadFor($resource, request(), $this->actor)->allowed());

        $this->scopeTo(['scoped-reports.view']);
        $verdict = $this->guard()->inspectReadFor($resource, request(), $this->actor);
        $this->assertTrue($verdict->denied(), 'the declared ability is itself narrowed by the credential-scope gate');
    }

    public function test_an_entitlement_read_gate_is_principal_only_so_the_read_still_asks_its_name(): void
    {
        $this->app->instance(ReadsReadPolicy::class, new ReadsReadPolicy(true));
        Gate::define('entitlement:reports.area', fn (?User $user = null) => $user !== null);
        $resource = $this->readPolicyResource('entitlement:reports.area');

        $this->scopeTo(['entity.view']);
        $verdict = $this->guard()->inspectReadFor($resource, request(), $this->actor);
        $this->assertInstanceOf(CredentialScopeRefusal::class, $verdict);

        $this->scopeTo(['scoped-reports.view']);
        $this->assertTrue($this->guard()->inspectReadFor($resource, request(), $this->actor)->allowed());
    }

    public function test_read_names_are_the_resource_key_and_the_models_derived_view_names(): void
    {
        $record = (new ReadsRecord)->forceFill(['id' => 42]);
        $record->exists = true;

        $this->assertSame(['scoped-reports.view', 'reads-record.view'], $this->guard()->readNames($this->readPolicyResource()));
        $this->assertSame(['scoped-reports.view', 'reads-record.view', 'reads-record.42.view'],
            $this->guard()->readNames($this->readPolicyResource(), $record));
        $this->assertSame(['unregistered-key.view'], $this->guard()->readNames('unregistered-key'));
    }

    public function test_every_registered_resource_offers_its_read_name_to_the_widest_set(): void
    {
        app(ParticleResourceRegistry::class)->register($this->readPolicyResource());

        $this->assertContains('scoped-reports.view', app(CredentialScopeGate::class)->offerableNames());
    }

    public function test_an_options_source_with_registered_names_is_asked_before_it_runs(): void
    {
        $this->scopeTo(['entity.view']);
        app(DecisionNames::class)->name(ResourceFilters::OPTIONS_DECISION.'agents', ['agent.view', 'agent.own.view']);
        $method = new \ReflectionMethod(ResourceFilters::class, 'authorizeOptionsSource');

        try {
            $method->invoke(app(ResourceFilters::class), 'agents');
            $this->fail('A scoped token without the options source\'s name read its rows.');
        } catch (CredentialScopeRefused $refused) {
            $this->assertSame(403, $refused->status());
        }

        $this->scopeTo(['agent.own.view']);
        $method->invoke(app(ResourceFilters::class), 'agents');

        // an options source nobody registered is part of the parent resource's (already named) read
        $this->scopeTo(['entity.view']);
        $method->invoke(app(ResourceFilters::class), 'guest_link_subject_kinds');
        $this->assertContains('agent.own.view', app(CredentialScopeGate::class)->offerableNames());
    }
}

class ReadsRecord extends Model
{
    public function getMorphClass()
    {
        return 'reads-record';
    }
}

class ReadsReadPolicy implements ResourceReadPolicy
{
    public function __construct(public bool $allow = true) {}

    public function inspect(?Authenticatable $actor, ParticleResource $resource, Request $request): Response
    {
        return $this->allow ? Response::allow() : Response::deny('Not a member.');
    }
}

class ReadsFixedScope implements CredentialScopeResolver
{
    /** @param  list<string>|null  $scope */
    public function __construct(private ?array $scope) {}

    public function resolve(): ?array
    {
        return $this->scope;
    }
}
