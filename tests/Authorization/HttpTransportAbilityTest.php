<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Rushing\PermissionCascade\Policies\BaseModelPolicy;
use Splicewire\Beam\Authorization\AbilityResolver;
use Splicewire\Beam\Authorization\ActorPort;
use Splicewire\Beam\Authorization\GuardActorAdapter;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-doctrine-convergence ticket 09 — the HTTP HALF of the cross-transport ability resolver.
 *
 * The resolver deliberately has NO unit seam of its own. It is tested through the two transports it
 * serves — a forbidden response here, tool-listing omission on the MCP side (beam-mcp's
 * `EntitlementToolAuthorizerTest`) — because a unit test on the resolver would keep passing while the
 * two transports drifted apart, which is precisely the failure mode duplicated permission logic
 * produces and the reason one resolver exists at all.
 *
 * What this file pins:
 *   - a denied operation answers a FORBIDDEN status (the transport owns its deny shape);
 *   - the decision with a subject is the policy cascade's, unchanged;
 *   - an operation declaring NO ability stays reachable (a regression here locks people out silently);
 *   - the actor comes from the {@see ActorPort}, provably — rebinding the port changes the outcome
 *     while the ambient guard user does not move.
 */
class HttpTransportAbilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gadgets', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });

        Gadget::create(['label' => 'one']);

        // The per-ACTION plane: a bare policy verb against the loaded subject. Only the owner may tune.
        Gate::define('tune', fn (?User $user, Gadget $gadget) => $user instanceof PrivilegedUser);
    }

    // ── The deny shape is the transport's ────────────────────────────────────────────────────────────

    public function test_an_operation_the_actor_may_not_invoke_answers_a_forbidden_status(): void
    {
        $this->mount($this->op(ability: 'tune'));
        $this->actingAs(new PlainUser);

        $this->postJson('/gadgets/1/tune')->assertForbidden();
    }

    public function test_a_guest_is_forbidden_rather_than_erroring(): void
    {
        $this->mount($this->op(ability: 'tune'));

        $this->postJson('/gadgets/1/tune')->assertForbidden();
    }

    public function test_an_operation_the_actor_may_invoke_runs(): void
    {
        $this->mount($this->op(ability: 'tune'));
        $this->actingAs(new PrivilegedUser);

        $this->postJson('/gadgets/1/tune')->assertOk()->assertJson(['ran' => true]);
    }

    // ── The handler must not run on a denial ─────────────────────────────────────────────────────────

    public function test_a_denied_operation_never_reaches_its_handler(): void
    {
        $ran = false;
        $this->mount($this->op(ability: 'tune', handle: function () use (&$ran) {
            $ran = true;

            return ['ran' => true];
        }));
        $this->actingAs(new PlainUser);

        $this->postJson('/gadgets/1/tune')->assertForbidden();

        $this->assertFalse($ran, 'A forbidden operation must be refused before its handler runs.');
    }

    // ── Regression floor: ungated operations stay reachable ──────────────────────────────────────────

    public function test_an_operation_declaring_no_ability_remains_reachable_for_any_actor(): void
    {
        $this->mount($this->op(name: 'ping', ability: null));

        // No actor at all — an ungated operation was never gated and must not become gated.
        $this->postJson('/gadgets/1/ping')->assertOk()->assertJson(['ran' => true]);

        $this->actingAs(new PlainUser);
        $this->postJson('/gadgets/1/ping')->assertOk()->assertJson(['ran' => true]);
    }

    public function test_a_cross_model_ability_is_checked_against_the_declared_class_not_the_instance(): void
    {
        // `abilityModel` names a CLASS-string (the `create`-verb shape). The subject-bearing branch must
        // pass it through to the gate untouched rather than treating a non-instance as "no subject".
        Gate::define('forge', fn (?User $user, string $class) => $class === Gadget::class && $user instanceof PrivilegedUser);

        $this->mount($this->op(name: 'forge', ability: 'forge', abilityModel: Gadget::class));

        $this->actingAs(new PlainUser);
        $this->postJson('/gadgets/1/forge')->assertForbidden();

        $this->actingAs(new PrivilegedUser);
        $this->postJson('/gadgets/1/forge')->assertOk();
    }

    public function test_tenant_mount_can_substitute_its_declared_author_ability_without_changing_central_routes(): void
    {
        $this->mountAuthorScopedOperation('central/gadgets', ['name' => 'central.tune']);
        $this->mountAuthorScopedOperation('tenant/gadgets', [
            'name' => 'tenant.tune',
            'realm' => 'tenant',
            'abilityOverrides' => ['ux.author' => 'site.edit'],
        ]);
        Particle::ops('untagged/gadgets', 'gadgets', 'tune', [
            'name' => 'untagged.tune',
            'abilityOverrides' => ['ux.author' => 'site.edit'],
        ]);

        $centralAuthor = new ScopedAbilityUser;
        $centralAuthor->uxAuthor = true;
        $tenantEditor = new ScopedAbilityUser;
        $tenantEditor->siteEdit = true;

        $this->actingAs($centralAuthor)->postJson('/tenant/gadgets/1/tune')->assertForbidden();
        $this->actingAs($tenantEditor)->postJson('/central/gadgets/1/tune')->assertForbidden();
        $this->actingAs($centralAuthor)->postJson('/central/gadgets/1/tune')->assertOk();
        $this->actingAs($tenantEditor)->postJson('/tenant/gadgets/1/tune')->assertOk();
        $this->actingAs($tenantEditor)->postJson('/untagged/gadgets/1/tune')->assertForbidden();
        $this->actingAs($centralAuthor)->postJson('/untagged/gadgets/1/tune')->assertOk();
    }

    public function test_each_tenant_mount_uses_only_its_own_author_override(): void
    {
        $this->mountAuthorScopedOperation('tenant-editor/gadgets', [
            'name' => 'tenant.editor.tune',
            'realm' => 'tenant',
            'abilityOverrides' => ['ux.author' => 'site.edit'],
        ]);
        $this->mountAuthorScopedOperation('tenant-strict/gadgets', [
            'name' => 'tenant.strict.tune',
            'realm' => 'tenant',
        ]);

        $centralAuthor = new ScopedAbilityUser;
        $centralAuthor->uxAuthor = true;
        $tenantEditor = new ScopedAbilityUser;
        $tenantEditor->siteEdit = true;

        $this->actingAs($tenantEditor)->postJson('/tenant-editor/gadgets/1/tune')->assertOk();
        $this->actingAs($tenantEditor)->postJson('/tenant-strict/gadgets/1/tune')->assertForbidden();
        $this->actingAs($centralAuthor)->postJson('/tenant-strict/gadgets/1/tune')->assertOk();
    }

    public function test_tenant_override_cannot_remap_an_ability_other_than_ux_author(): void
    {
        Gate::define('entitlement:tune', fn ($user): bool => $user instanceof ScopedAbilityUser && $user->uxAuthor);
        $operation = $this->op(name: 'generic', ability: 'tune', abilityModel: false);
        $this->app->make(ParticleOperationRegistry::class)->register($operation);
        Particle::ops('tenant-generic/gadgets', 'gadgets', 'generic', [
            'realm' => 'tenant',
            'abilityOverrides' => ['tune' => 'site.edit'],
        ]);

        $tenantEditor = new ScopedAbilityUser;
        $tenantEditor->siteEdit = true;
        $centralAuthor = new ScopedAbilityUser;
        $centralAuthor->uxAuthor = true;

        $this->actingAs($tenantEditor)->postJson('/tenant-generic/gadgets/1/generic')->assertForbidden();
        $this->actingAs($centralAuthor)->postJson('/tenant-generic/gadgets/1/generic')->assertOk();
    }

    // ── Launch 72 (root Option A, an AUTHORIZATION change): a subject-free override target is a permission ─────

    public function test_a_subject_free_tenant_override_to_a_role_permission_asks_the_actors_can(): void
    {
        $this->mountAuthorScopedOperation('tenant/gadgets', [
            'name' => 'tenant.tune',
            'realm' => 'tenant',
            'abilityOverrides' => ['ux.author' => 'site.edit'],
        ]);

        $withPermission = new ScopedAbilityUser;
        $withPermission->siteEdit = true;
        $without = new ScopedAbilityUser;
        $centralAuthor = new ScopedAbilityUser;
        $centralAuthor->uxAuthor = true;

        $this->actingAs($withPermission)->postJson('/tenant/gadgets/1/tune')->assertOk();
        $this->actingAs($without)->postJson('/tenant/gadgets/1/tune')->assertForbidden();
        $this->actingAs($centralAuthor)->postJson('/tenant/gadgets/1/tune')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson('/tenant/gadgets/1/tune')->assertForbidden();
    }

    public function test_a_permission_override_is_not_answered_by_an_entitlement_of_the_same_name(): void
    {
        $this->mountAuthorScopedOperation('tenant/gadgets', [
            'name' => 'tenant.tune',
            'realm' => 'tenant',
            'abilityOverrides' => ['ux.author' => 'site.edit'],
        ]);
        // A permissive entitlement under the target's name must NOT admit: the target is the permission itself.
        Gate::define('entitlement:site.edit', fn ($user = null): bool => true);

        $this->actingAs(new ScopedAbilityUser)->postJson('/tenant/gadgets/1/tune')->assertForbidden();
    }

    public function test_an_entitlement_prefixed_override_still_resolves_through_the_entitlement_gate(): void
    {
        $this->mountAuthorScopedOperation('tenant/gadgets', [
            'name' => 'tenant.tune',
            'realm' => 'tenant',
            'abilityOverrides' => ['ux.author' => 'entitlement:workbench.enter'],
        ]);
        Gate::define('entitlement:workbench.enter', fn ($user = null): bool => $user instanceof PrivilegedUser);
        Gate::define('workbench.enter', fn ($user = null): bool => true);

        $this->actingAs(new PrivilegedUser)->postJson('/tenant/gadgets/1/tune')->assertOk();
        // Holding a same-named PERMISSION is not enough: the prefixed form keeps the entitlement plane.
        $this->actingAs(new PlainUser)->postJson('/tenant/gadgets/1/tune')->assertForbidden();
    }

    public function test_an_operator_realm_mount_without_an_override_keeps_the_entitlement_plane(): void
    {
        $this->mountAuthorScopedOperation('operator/gadgets', ['name' => 'operator.tune', 'realm' => 'operator']);
        // A same-named permission must not admit a declared (non-overridden) subject-free ability.
        Gate::define('ux.author', fn ($user = null): bool => true);

        $centralAuthor = new ScopedAbilityUser;
        $centralAuthor->uxAuthor = true;
        $tenantEditor = new ScopedAbilityUser;
        $tenantEditor->siteEdit = true;

        $this->actingAs($centralAuthor)->postJson('/operator/gadgets/1/tune')->assertOk();
        $this->actingAs($tenantEditor)->postJson('/operator/gadgets/1/tune')->assertForbidden();
        $this->actingAs(new ScopedAbilityUser)->postJson('/operator/gadgets/1/tune')->assertForbidden();
    }

    public function test_an_overridden_ability_with_a_subject_stays_on_the_policy_plane(): void
    {
        $operation = $this->op(name: 'polish', ability: 'ux.author');
        $this->app->make(ParticleOperationRegistry::class)->register($operation);
        Particle::ops('tenant-subject/gadgets', 'gadgets', 'polish', [
            'realm' => 'tenant',
            'abilityOverrides' => ['ux.author' => 'site.edit'],
        ]);
        // The subject-bearing check is the policy cascade's, with the Gadget: a subject-free `site.edit` alone does not admit.
        Gate::define('site.edit', fn ($user, $subject = null): bool => $subject instanceof Gadget && $user instanceof PrivilegedUser);

        $this->actingAs(new PrivilegedUser)->postJson('/tenant-subject/gadgets/1/polish')->assertOk();
        $this->actingAs(new PlainUser)->postJson('/tenant-subject/gadgets/1/polish')->assertForbidden();
    }

    // ── Standwell gap-assessment-build 36 (ADR-0109): a scoped credential narrows the permission plane ────────

    public function test_a_scoped_credential_without_the_permission_is_refused_a_named_ability_on_a_subject(): void
    {
        // The reproduction: the principal holds the permission (the Gate answers yes, as spatie's `before` does),
        // but the acting token is scoped to something else. Before 36 this answered 200.
        Gate::define('gadgets.tune', fn ($user = null, $subject = null): bool => $user instanceof PrivilegedUser);
        $this->mount($this->op(name: 'named', ability: 'gadgets.tune', abilityModel: Gadget::class));
        $this->actingAs(new PrivilegedUser);

        $this->scopeCredentialTo(['gadgets.view']);
        $this->postJson('/gadgets/1/named')->assertForbidden();

        $this->scopeCredentialTo(['gadgets.view', 'gadgets.tune']);
        $this->postJson('/gadgets/1/named')->assertOk();

        // Unscoped (a session, a `*` token): unchanged.
        $this->scopeCredentialTo(null);
        $this->postJson('/gadgets/1/named')->assertOk();
    }

    public function test_a_scope_never_grants_what_the_principal_lacks(): void
    {
        Gate::define('gadgets.tune', fn ($user = null, $subject = null): bool => $user instanceof PrivilegedUser);
        $this->mount($this->op(name: 'named', ability: 'gadgets.tune'));
        $this->actingAs(new PlainUser);
        $this->scopeCredentialTo(['gadgets.tune']);

        $this->postJson('/gadgets/1/named')->assertForbidden();
    }

    public function test_a_policy_verb_is_left_to_the_policy_under_a_scoped_credential(): void
    {
        // `tune` is a verb: the policy owns the name it derives to (the cascade narrows that itself), so a scope of
        // permission names that does not literally contain `tune` must not refuse it.
        $this->mount($this->op(ability: 'tune'));
        $this->actingAs(new PrivilegedUser);
        $this->scopeCredentialTo(['gadgets.view']);

        $this->postJson('/gadgets/1/tune')->assertOk();
    }

    public function test_a_subject_free_permission_override_is_narrowed_by_a_scoped_credential(): void
    {
        $this->mountAuthorScopedOperation('tenant/gadgets', [
            'name' => 'tenant.tune',
            'realm' => 'tenant',
            'abilityOverrides' => ['ux.author' => 'site.edit'],
        ]);
        $editor = new ScopedAbilityUser;
        $editor->siteEdit = true;
        $this->actingAs($editor);

        $this->scopeCredentialTo(['gadgets.view']);
        $this->postJson('/tenant/gadgets/1/tune')->assertForbidden();

        $this->scopeCredentialTo(['site.edit']);
        $this->postJson('/tenant/gadgets/1/tune')->assertOk();
    }

    public function test_the_entitlement_plane_is_not_narrowed_by_a_scoped_credential(): void
    {
        // An entitlement is the tenant's plan feature, not a principal permission (ADR-0109 decision 1).
        Gate::define('entitlement:workbench.enter', fn ($user = null): bool => $user instanceof PrivilegedUser);
        $this->mount($this->op(name: 'subject-free', ability: 'workbench.enter', abilityModel: false));
        $this->actingAs(new PrivilegedUser);
        $this->scopeCredentialTo(['gadgets.view']);

        $this->postJson('/gadgets/1/subject-free')->assertOk();
    }

    public function test_a_gate_before_grant_does_not_carry_a_scoped_credential_past_a_cascade_backed_verb(): void
    {
        // The pre-land review's bypass: a bare verb on a cascade-backed model is narrowed by `BaseModelPolicy`, but a
        // host's `Gate::before` (the flagship's Root) answers yes before that policy runs. A Root token scoped to
        // `cascade-gadget.view` must still be refused `update`; a token carrying the derived name is admitted.
        Schema::create('cascade_gadgets', function (Blueprint $table): void {
            $table->id();
        });
        CascadeGadget::create();
        Gate::policy(CascadeGadget::class, CascadeGadgetPolicy::class);
        Gate::before(fn ($user = null) => $user instanceof RootUser ? true : null);
        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'cascade-gadgets',
            name: 'reset',
            kind: OperationKind::Write,
            model: CascadeGadget::class,
            handle: fn () => ['ran' => true],
            ability: 'update',
        ));
        Particle::ops('cascade-gadgets', 'cascade-gadgets', 'reset');
        $this->actingAs(new RootUser);

        $this->scopeCredentialTo(['cascade-gadget.view']);
        $this->postJson('/cascade-gadgets/1/reset')->assertForbidden();

        $this->scopeCredentialTo(['cascade-gadget.view', 'cascade-gadget.update']);
        $this->postJson('/cascade-gadgets/1/reset')->assertOk();

        // Unscoped (a session, a `*` token): unchanged.
        $this->scopeCredentialTo(null);
        $this->postJson('/cascade-gadgets/1/reset')->assertOk();
    }

    public function test_a_cascade_backed_verb_without_a_before_grant_is_unchanged_under_a_scope(): void
    {
        // A principal holding the derived permission outright: the policy runs and narrows, as before the review fix.
        Schema::create('cascade_gadgets', function (Blueprint $table): void {
            $table->id();
        });
        CascadeGadget::create();
        Gate::policy(CascadeGadget::class, CascadeGadgetPolicy::class);
        Gate::define('cascade-gadget.update', fn ($user = null): bool => $user instanceof PrivilegedUser);
        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'cascade-gadgets',
            name: 'reset',
            kind: OperationKind::Write,
            model: CascadeGadget::class,
            handle: fn () => ['ran' => true],
            ability: 'update',
        ));
        Particle::ops('cascade-gadgets', 'cascade-gadgets', 'reset');
        $this->actingAs(new PrivilegedUser);

        $this->scopeCredentialTo(['cascade-gadget.view']);
        $this->postJson('/cascade-gadgets/1/reset')->assertForbidden();

        $this->scopeCredentialTo(['cascade-gadget.update']);
        $this->postJson('/cascade-gadgets/1/reset')->assertOk();
    }

    /** @param  list<string>|null  $scope */
    private function scopeCredentialTo(?array $scope): void
    {
        $this->app->instance(CredentialScopeResolver::class, new FixedCredentialScope($scope));
    }

    // ── The actor arrives through the port, not ambient auth ─────────────────────────────────────────

    public function test_the_decision_follows_the_actor_port_rather_than_the_ambient_guard_user(): void
    {
        $this->mount($this->op(ability: 'tune'));

        // Ambient auth says PLAIN (would be forbidden); the port says PRIVILEGED. The port wins, which is
        // only possible because the controller never reads `$request->user()` for the decision — the same
        // substitution the stdio MCP transport relies on, since it has no ambient user to read.
        $this->actingAs(new PlainUser);
        $this->app->instance(ActorPort::class, new FixedActorPort(new PrivilegedUser));

        $this->postJson('/gadgets/1/tune')->assertOk();
    }

    public function test_the_default_port_binding_reports_the_guard_user(): void
    {
        $this->assertInstanceOf(GuardActorAdapter::class, $this->app->make(ActorPort::class));

        $user = new PrivilegedUser;
        $this->actingAs($user);

        $this->assertSame($user, $this->app->make(ActorPort::class)->actor());
    }

    // ── The subject-bearing decision IS the policy cascade's ─────────────────────────────────────────

    public function test_the_subject_bearing_decision_matches_the_gate_for_the_same_actor(): void
    {
        $resolver = $this->app->make(AbilityResolver::class);
        $gate = $this->app->make(GateContract::class);
        $gadget = Gadget::first();

        foreach ([new PlainUser, new PrivilegedUser, null] as $actor) {
            $this->assertSame(
                $gate->forUser($actor)->allows('tune', $gadget),
                $resolver->allows($actor, 'tune', $gadget),
                'The resolver must not introduce a second answer to a policy question.',
            );
        }
    }

    // ── `ability:` is three-state, and `abilityModel:` chooses the plane (ticket 03) ────────────────

    public function test_a_deliberately_ungated_operation_runs_for_anyone(): void
    {
        // `false` and `null` behave identically at the gate TODAY, and must: the difference between
        // them is whether a human decided it, which is a declaration fact. What this pins is that
        // introducing the third state did not accidentally close a surface — `StopImpersonating` is the
        // live instance, and an ability there locks the operator inside the impersonated session.
        $this->mount($this->op(name: 'stop', ability: false));

        $this->postJson('/gadgets/1/stop')->assertOk()->assertJson(['ran' => true]);
    }

    public function test_ability_model_false_routes_the_check_to_the_subject_free_entitlement_plane(): void
    {
        // The declared override the derivation needs. `ux.author` is an ENTITLEMENT key; declared with
        // `abilityModel: null` it is checked as a POLICY verb against the loaded subject, which is a
        // different question with a different answer. Both halves are asserted, because only the
        // contrast proves the flag did anything.
        Gate::define('entitlement:workbench.enter', fn (?User $user) => $user instanceof PrivilegedUser);
        Gate::define('workbench.enter', fn (?User $user, Gadget $gadget) => false);

        $this->mount($this->op(name: 'subject-free', ability: 'workbench.enter', abilityModel: false));
        $this->mount($this->op(name: 'subject-bound', ability: 'workbench.enter'));

        $this->actingAs(new PrivilegedUser);

        $this->postJson('/gadgets/1/subject-free')->assertOk();
        $this->postJson('/gadgets/1/subject-bound')->assertForbidden();
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────

    private function op(
        string $name = 'tune',
        string|false|null $ability = 'tune',
        string|false|null $abilityModel = null,
        ?callable $handle = null,
    ): ParticleOperation {
        return new ParticleOperation(
            resource: 'gadgets',
            name: $name,
            kind: OperationKind::Write,
            model: Gadget::class,
            handle: $handle === null ? fn () => ['ran' => true] : \Closure::fromCallable($handle),
            ability: $ability,
            abilityModel: $abilityModel,
        );
    }

    private function mount(ParticleOperation $operation): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register($operation);

        Particle::ops('gadgets', 'gadgets', $operation->name);
    }

    private function mountAuthorScopedOperation(string $uri, array $options): void
    {
        $operation = $this->op(ability: 'ux.author', abilityModel: false);
        $registry = $this->app->make(ParticleOperationRegistry::class);
        if (! $registry->has('gadgets', $operation->name)) {
            $registry->register($operation);
        }
        Particle::ops($uri, 'gadgets', $operation->name, $options);

        Gate::define('entitlement:ux.author', fn ($user): bool => $user instanceof ScopedAbilityUser && $user->uxAuthor);
        // `site.edit` is a ROLE PERMISSION (tower's PermissionsSeeder), the actor's `can('site.edit')`: launch 72 asks
        // a subject-free override target as itself. Nothing defines `entitlement:site.edit` (see the strictness test).
        Gate::define('site.edit', fn ($user): bool => $user instanceof ScopedAbilityUser && $user->siteEdit);
    }
}

class Gadget extends Model
{
    protected $table = 'gadgets';

    public $timestamps = false;

    protected $guarded = [];
}

class CascadeGadget extends Model
{
    protected $table = 'cascade_gadgets';

    public $timestamps = false;

    protected $guarded = [];

    public function getMorphClass()
    {
        return 'cascade-gadget';
    }
}

class CascadeGadgetPolicy extends BaseModelPolicy
{
    public static $defaultModelClass = CascadeGadget::class;
}

/** A principal a host's `Gate::before` answers yes for (the flagship's Root). */
class RootUser extends User
{
    protected $table = 'users';
}

class PlainUser extends User
{
    protected $table = 'users';
}

class PrivilegedUser extends User
{
    protected $table = 'users';
}

class ScopedAbilityUser extends User
{
    protected $table = 'users';

    public bool $uxAuthor = false;

    public bool $siteEdit = false;
}

/** A port answering a FIXED actor — what a transport with no ambient user (MCP over stdio) binds. */
class FixedActorPort implements ActorPort
{
    public function __construct(private mixed $actor) {}

    public function actor(): mixed
    {
        return $this->actor;
    }
}

class FixedCredentialScope implements CredentialScopeResolver
{
    /** @param  list<string>|null  $scope */
    public function __construct(private ?array $scope) {}

    public function resolve(): ?array
    {
        return $this->scope;
    }
}
