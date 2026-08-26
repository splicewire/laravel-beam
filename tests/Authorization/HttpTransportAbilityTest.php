<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Authorization\AbilityResolver;
use Splicewire\Beam\Authorization\ActorPort;
use Splicewire\Beam\Authorization\GuardActorAdapter;
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

        $this->postJson('/gadgets/1/op/tune')->assertForbidden();
    }

    public function test_a_guest_is_forbidden_rather_than_erroring(): void
    {
        $this->mount($this->op(ability: 'tune'));

        $this->postJson('/gadgets/1/op/tune')->assertForbidden();
    }

    public function test_an_operation_the_actor_may_invoke_runs(): void
    {
        $this->mount($this->op(ability: 'tune'));
        $this->actingAs(new PrivilegedUser);

        $this->postJson('/gadgets/1/op/tune')->assertOk()->assertJson(['ran' => true]);
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

        $this->postJson('/gadgets/1/op/tune')->assertForbidden();

        $this->assertFalse($ran, 'A forbidden operation must be refused before its handler runs.');
    }

    // ── Regression floor: ungated operations stay reachable ──────────────────────────────────────────

    public function test_an_operation_declaring_no_ability_remains_reachable_for_any_actor(): void
    {
        $this->mount($this->op(name: 'ping', ability: null));

        // No actor at all — an ungated operation was never gated and must not become gated.
        $this->postJson('/gadgets/1/op/ping')->assertOk()->assertJson(['ran' => true]);

        $this->actingAs(new PlainUser);
        $this->postJson('/gadgets/1/op/ping')->assertOk()->assertJson(['ran' => true]);
    }

    public function test_a_cross_model_ability_is_checked_against_the_declared_class_not_the_instance(): void
    {
        // `abilityModel` names a CLASS-string (the `create`-verb shape). The subject-bearing branch must
        // pass it through to the gate untouched rather than treating a non-instance as "no subject".
        Gate::define('forge', fn (?User $user, string $class) => $class === Gadget::class && $user instanceof PrivilegedUser);

        $this->mount($this->op(name: 'forge', ability: 'forge', abilityModel: Gadget::class));

        $this->actingAs(new PlainUser);
        $this->postJson('/gadgets/1/op/forge')->assertForbidden();

        $this->actingAs(new PrivilegedUser);
        $this->postJson('/gadgets/1/op/forge')->assertOk();
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

        $this->postJson('/gadgets/1/op/tune')->assertOk();
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

        $this->postJson('/gadgets/1/op/stop')->assertOk()->assertJson(['ran' => true]);
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

        $this->postJson('/gadgets/1/op/subject-free')->assertOk();
        $this->postJson('/gadgets/1/op/subject-bound')->assertForbidden();
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

        Route::particleOp('gadgets', 'gadgets', $operation->name);
    }
}

class Gadget extends Model
{
    protected $table = 'gadgets';

    public $timestamps = false;

    protected $guarded = [];
}

class PlainUser extends User
{
    protected $table = 'users';
}

class PrivilegedUser extends User
{
    protected $table = 'users';
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
