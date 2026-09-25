<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Schemastud\Frame\Authorization\OpenResourceAccessGate;
use Schemastud\Frame\Contracts\ResourceAccessGate;
use Schemastud\Frame\Contracts\ResourceActionAuthorizer;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Registry\ResourceActionDefinition;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Doctor\UndeclaredAffordanceAudit;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Frame\ParticleActionAuthorizer;
use Splicewire\Beam\Particle\ActionAffordance;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\Subject\ActorSubject;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Particle\Subject\ParentSubject;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-operation-surface 21 (ADR-0223) — a `#[ParticleOp]` that declares `affordance:` is projected onto
 * frame's generic ACTION concept by `toResourceDefinition()`, and its per-actor visibility is the op's own
 * `ability:`, asked through the resolver the mount enforces.
 *
 * Every authorization reading is taken with the gate CLOSED, asserted in the test, per AGENTS.md: a reading
 * under an open gate cannot tell a projected ability from a permissive harness.
 */
class ParticleResourceActionsTest extends TestCase
{
    /**
     * Frame's provider is listed LAST on purpose: it binds a deny-everything action port in its own
     * register(), so this order is the one where beam's `booted()` re-bind is what makes the answer beam's.
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('frame.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('vaults', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'vaults',
            backing: ActionVault::class,
            data: ActionVaultData::class,
            label: 'Vaults',
            readOnly: true,
        ));

        Gate::define('entitlement:vaults.manage', fn ($user = null): bool => $user instanceof ActionOwner);
        Gate::policy(ActionVault::class, ActionVaultPolicy::class);
    }

    private function op(string $name, mixed $subject, mixed $affordance, string|false|null $ability = 'vaults.manage', mixed $abilityModel = false, string|false|null $input = null): ParticleOperation
    {
        $op = new ParticleOperation(
            resource: 'vaults',
            name: $name,
            kind: OperationKind::Write,
            handle: fn () => ['data' => ['ok' => true]],
            ability: $ability,
            abilityModel: $abilityModel,
            input: $input,
            subject: $subject,
            affordance: $affordance,
        );

        $this->app->make(ParticleOperationRegistry::class)->register($op);

        return $op;
    }

    /** @return array<string, ResourceActionDefinition> */
    private function actions(): array
    {
        $definition = $this->app->make(ParticleResourceRegistry::class)->definition('vaults');

        return collect($definition->actions)->keyBy('key')->all();
    }

    private function assertGateClosed(mixed $actor): void
    {
        $this->assertFalse(
            Gate::forUser($actor)->allows('probe-nonexistent-ability'),
            'The gate is OPEN in this harness; no authorization reading below can be trusted.',
        );
    }

    public function test_opted_in_ops_project_with_their_placement_url_input_and_presentation(): void
    {
        $this->op('reload', NoSubject::class, new ActionAffordance(label: 'Reload vault'), input: ActionReloadInputData::class);
        $this->op('seal', null, new ActionAffordance(result: 'navigate', destructive: true), ability: 'update', abilityModel: null, input: false);
        $this->op('mine', ActorSubject::class, 'action', ability: false, abilityModel: null, input: false);

        Particle::ops('api/vaults', 'vaults', ['reload', 'seal', 'mine']);

        $actions = $this->actions();

        $this->assertSame(['reload', 'seal', 'mine'], array_keys($actions));

        $this->assertSame([
            'key' => 'reload',
            'label' => 'Reload vault',
            'scope' => 'resource',
            'method' => 'POST',
            'url' => '/api/vaults/reload',
            'input' => ActionReloadInputData::class,
            'result' => 'toast',
            'destructive' => false,
        ], $actions['reload']->all());

        // A record op: a row and detail action whose URL keeps the `{id}` the client fills. Confirm-only.
        $this->assertSame('record', $actions['seal']->scope);
        $this->assertSame('/api/vaults/{id}/seal', $actions['seal']->url);
        $this->assertNull($actions['seal']->input);
        $this->assertSame('navigate', $actions['seal']->result);
        $this->assertTrue($actions['seal']->destructive);

        // The shorthand takes every default; the label is the op's name, headlined.
        $this->assertSame('Mine', $actions['mine']->label);
        $this->assertSame('resource', $actions['mine']->scope);

        // On the wire, the input is the generated type's dot-form name: no class-string (ADR-0004).
        $wire = $this->app->make(ParticleResourceRegistry::class)->definition('vaults')->toArray();
        $this->assertSame('Splicewire.Beam.Tests.Frame.ActionReloadInputData', $wire['actions'][0]['input']);
    }

    public function test_false_undeclared_parent_subject_and_unmounted_ops_project_nothing(): void
    {
        $this->op('declared-off', NoSubject::class, false);
        $this->op('undeclared', NoSubject::class, null);
        $this->op('under-edge', ParentSubject::class, 'action');
        $this->op('never-mounted', NoSubject::class, 'action');

        Particle::ops('api/vaults', 'vaults', ['declared-off', 'undeclared', 'under-edge']);

        $this->assertSame([], $this->actions());
    }

    public function test_the_slot_refuses_a_string_other_than_the_shorthand(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("affordance: 'button'");

        new ParticleOperation(resource: 'vaults', name: 'x', kind: OperationKind::Write, handle: fn () => null, affordance: 'button');
    }

    public function test_an_affordance_result_outside_the_two_presentations_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ActionAffordance(result: 'modal');
    }

    public function test_visibility_is_the_ops_own_ability_on_the_plane_the_mount_checks(): void
    {
        $this->op('reload', NoSubject::class, 'action', input: false);
        $this->op('seal', null, 'action', ability: 'update', abilityModel: null, input: false);
        $this->op('open', NoSubject::class, 'action', ability: false, input: false);
        Particle::ops('api/vaults', 'vaults', ['reload', 'seal', 'open']);

        $this->assertInstanceOf(ParticleActionAuthorizer::class, $this->app->make(ResourceActionAuthorizer::class));

        $owner = new ActionOwner;
        $member = new ActionMember;
        $this->assertGateClosed($member);

        $definition = $this->app->make(ParticleResourceRegistry::class)->definition('vaults');
        $authorizer = $this->app->make(ResourceActionAuthorizer::class);
        $action = fn (string $key) => $definition->action($key);

        $this->actingAs($owner);
        $this->assertTrue($authorizer->allows($definition, $action('reload')), 'the entitlement plane grants the owner');
        $this->assertTrue($authorizer->allows($definition, $action('seal')), 'the policy grants the owner at class level');
        $this->assertTrue($authorizer->allows($definition, $action('open')), 'a declared-ungated op is drawn');

        $this->actingAs($member);
        $this->assertFalse($authorizer->allows($definition, $action('reload')));
        $this->assertFalse($authorizer->allows($definition, $action('seal')));
        $this->assertTrue($authorizer->allows($definition, $action('open')));

        // And the mount refuses the member exactly where the button is hidden.
        $this->postJson('/api/vaults/reload')->assertForbidden();
        $vault = ActionVault::create(['name' => 'one']);
        $this->postJson("/api/vaults/{$vault->getKey()}/seal")->assertForbidden();
        $this->postJson('/api/vaults/open')->assertOk();

        $this->actingAs($owner);
        $this->postJson('/api/vaults/reload')->assertOk();
    }

    public function test_the_manifest_carries_the_actions_and_the_per_actor_map(): void
    {
        $this->app->bind(ResourceAccessGate::class, OpenResourceAccessGate::class);
        $this->op('reload', NoSubject::class, new ActionAffordance(label: 'Reload vault'), input: ActionReloadInputData::class);
        Particle::ops('api/vaults', 'vaults', ['reload']);

        $this->actingAs(new ActionOwner);
        $owner = $this->getJson('/frame/manifest')->assertOk()->json('contexts.vaults');

        $this->assertSame('Reload vault', $owner['actions'][0]['label']);
        $this->assertSame('/api/vaults/reload', $owner['actions'][0]['url']);
        $this->assertSame(['reload' => true], $owner['can']['actions']);

        $this->actingAs(new ActionMember);
        $this->assertSame(
            ['reload' => false],
            $this->getJson('/frame/manifest')->assertOk()->json('contexts.vaults.can.actions'),
        );

        // The form's schema, fetched by the type name the wire carries, keyed as the op's URL accepts it.
        $schema = $this->getJson('/frame/resources/vaults/actions/reload/schema')->assertOk()->json();
        $this->assertArrayHasKey('amount_usd', $schema['properties']);
    }

    public function test_the_audit_counts_undeclared_writes_and_unplaceable_affordances(): void
    {
        $this->op('declared-off', NoSubject::class, false);
        $this->op('undeclared', NoSubject::class, null);
        $this->op('under-edge', ParentSubject::class, 'action');

        $findings = collect($this->app->make(UndeclaredAffordanceAudit::class)->run())->keyBy('check');

        $undeclared = $findings[UndeclaredAffordanceAudit::CHECK_UNDECLARED];
        $this->assertSame('warn', $undeclared->status->value);
        $this->assertStringContainsString('vaults.undeclared', $undeclared->detail);
        $this->assertStringNotContainsString('vaults.declared-off', $undeclared->detail);

        $placement = $findings[UndeclaredAffordanceAudit::CHECK_UNPLACEABLE];
        $this->assertSame('warn', $placement->status->value);
        $this->assertStringContainsString('vaults.under-edge', $placement->detail);
    }
}

class ActionVault extends Model
{
    protected $table = 'vaults';

    public $timestamps = false;

    protected $guarded = [];
}

class ActionVaultData extends Data
{
    public function __construct(public int $id, public ?string $name = null) {}
}

class ActionReloadInputData extends Data
{
    public function __construct(
        #[MapInputName('amount_usd')]
        public float $amountUsd = 0,
    ) {}
}

class ActionVaultPolicy
{
    public function update(User $user, ActionVault $vault): bool
    {
        return $user instanceof ActionOwner;
    }
}

class ActionOwner extends User
{
    protected $table = 'users';

    public function getAuthIdentifier(): mixed
    {
        return 1;
    }
}

class ActionMember extends User
{
    protected $table = 'users';

    public function getAuthIdentifier(): mixed
    {
        return 2;
    }
}
