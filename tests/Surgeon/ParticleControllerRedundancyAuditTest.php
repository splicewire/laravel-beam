<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Surgeon\Operation\FixableFinding;
use Splicewire\Beam\Particle\Attributes\BespokeByDesign;
use Splicewire\Beam\Surgeon\ParticleControllerRedundancyAudit;

/**
 * The hunt-02 dogfood (refactor-tooling ticket 16): four V1 controllers are thin `ParticleController`
 * CRUD shells on already-registered resources whose peers (`tags`/`media`/`activity`) run controller-free
 * via `Particle::mount()`. This proves {@see ParticleControllerRedundancyAudit} flags exactly the
 * redundant shells — pure passthrough → fixable collapse; with-delta → advisory naming the blocker — and
 * leaves a non-particle controller, an unregistered resource, and an already-mounted key untouched.
 *
 * The `suggestFor()` tests are pure over in-memory fixtures (no registry, no route table, no DB); the
 * `parseController()` / `mountedKeysIn()` tests exercise the real AST classification off heredoc source.
 */
class ParticleControllerRedundancyAuditTest extends TestCase
{
    private function audit(): ParticleControllerRedundancyAudit
    {
        // dirs unused by suggestFor()/parseController(); no registry (pure-unit path).
        return new ParticleControllerRedundancyAudit('/nonexistent', '/nonexistent');
    }

    // ── suggestFor(): the three-leg invariant + the fix split ──────────────────────────────────────────

    public function test_a_pure_passthrough_shell_is_a_fixable_collapse(): void
    {
        $controllers = [[
            'class' => 'App\\Http\\Controllers\\Api\\V1\\AgentController',
            'file' => '/app/AgentController.php',
            'extendsParticleBase' => true,
            'resourceKey' => 'agents',
            'actions' => ['index' => 'passthrough', 'show' => 'passthrough', 'create' => 'passthrough'],
        ]];

        $findings = $this->audit()->suggestFor(
            $controllers,
            ['agents' => true],            // hand-wired
            ['tags' => true],              // a PEER rides the front door (not the agents key itself)
            ['agents' => true],            // registered
        );

        $this->assertCount(1, $findings);
        /** @var FixableFinding $finding */
        $finding = $findings[0];

        $this->assertTrue($finding->isFixable());
        $this->assertSame('warn', $finding->finding->status->value);
        $this->assertSame('particle.controller-redundant', $finding->finding->check);
        $this->assertNotNull($finding->suggestion);
        $this->assertSame('particle-resource-collapse', $finding->suggestion->kind);
        $this->assertSame('agents', $finding->suggestion->payload['resourceKey']);
        $this->assertSame($controllers[0]['class'], $finding->suggestion->payload['controller']);
    }

    public function test_a_shell_with_a_real_delta_is_advisory_naming_the_blocker(): void
    {
        $controllers = [[
            'class' => 'App\\Http\\Controllers\\Api\\V1\\ContextScopeController',
            'file' => '/app/ContextScopeController.php',
            'extendsParticleBase' => true,
            'resourceKey' => 'context-scopes',
            // index (custom query), embeddings (non-CRUD) are deltas; create is a passthrough.
            'actions' => ['index' => 'delta', 'create' => 'passthrough', 'embeddings' => 'delta'],
        ]];

        $findings = $this->audit()->suggestFor(
            $controllers,
            ['context-scopes' => true],
            ['tags' => true],
            ['context-scopes' => true],
        );

        $this->assertCount(1, $findings);
        $finding = $findings[0];

        $this->assertFalse($finding->isFixable());
        $this->assertTrue($finding->isAdvisory());
        $this->assertNotNull($finding->suggestion);
        $this->assertTrue($finding->suggestion->isAdvisory());
        $this->assertSame('splicewire/laravel-beam', $finding->suggestion->owningPackage);
        // The blocking delta actions are named in the advisory summary.
        $this->assertStringContainsString('index', $finding->suggestion->summary);
        $this->assertStringContainsString('embeddings', $finding->suggestion->summary);
    }

    // ── #[BespokeByDesign] acknowledgment: WARN downgrades to a Pass that still carries the reason ────

    public function test_an_acknowledged_shell_downgrades_to_a_pass_surfacing_the_reason(): void
    {
        $controllers = [[
            'class' => AcknowledgedShellFixtureController::class,
            'file' => '/app/AcknowledgedShellFixtureController.php',
            'extendsParticleBase' => true,
            'resourceKey' => 'context-scopes',
            'actions' => ['index' => 'delta', 'create' => 'passthrough'],
        ]];

        $findings = $this->audit()->suggestFor(
            $controllers,
            ['context-scopes' => true],
            ['tags' => true],
            ['context-scopes' => true],
        );

        $this->assertCount(1, $findings);
        /** @var FixableFinding $finding */
        $finding = $findings[0];

        $this->assertSame('pass', $finding->finding->status->value);
        $this->assertSame('particle.controller-redundant', $finding->finding->check);
        $this->assertStringContainsString('acknowledged: legacy envelope deltas the declaration cannot express', $finding->finding->detail);
        $this->assertNull($finding->suggestion);
        $this->assertFalse($finding->isFixable());
        $this->assertFalse($finding->isAdvisory());
    }

    public function test_an_acknowledged_pure_passthrough_shell_also_downgrades_never_collapses(): void
    {
        // Even a fully collapsible shell stands down when acknowledged — the human's documented call
        // outranks the mechanical fix, and the Pass line keeps the divergence on the ledger.
        $controllers = [[
            'class' => AcknowledgedShellFixtureController::class,
            'file' => '/app/AcknowledgedShellFixtureController.php',
            'extendsParticleBase' => true,
            'resourceKey' => 'context-scopes',
            'actions' => ['index' => 'passthrough', 'show' => 'passthrough'],
        ]];

        $findings = $this->audit()->suggestFor(
            $controllers,
            ['context-scopes' => true],
            ['tags' => true],
            ['context-scopes' => true],
        );

        $this->assertCount(1, $findings);
        $this->assertSame('pass', $findings[0]->finding->status->value);
        $this->assertNull($findings[0]->suggestion);
    }

    public function test_a_non_particle_controller_with_no_model_touching_crud_is_not_flagged(): void
    {
        $controllers = [[
            'class' => 'App\\Http\\Controllers\\Api\\LeadController',
            'file' => '/app/LeadController.php',
            'extendsParticleBase' => false,      // structural leg fails…
            'resourceKey' => 'leads',
            'actions' => ['store' => 'passthrough'],
            'crudModels' => [],                  // …and the behavior path sees no model-touching CRUD
        ]];

        $this->assertSame([], $this->audit()->suggestFor(
            $controllers,
            ['leads' => true],
            ['tags' => true],
            ['leads' => true],
        ));
    }

    // ── the BEHAVIOR path: no particle base required (the PlanController blindness fix) ────────────────

    public function test_a_non_particle_crud_controller_on_a_registered_model_is_flagged_advisory(): void
    {
        // Shaped like commerce's Operator/PlanController: a plain Controller whose `index` lists the
        // registered `plans` resource's Plan model — invisible to the old inheritance-keyed leg.
        $controllers = [[
            'class' => 'Splicewire\\Beam\\Commerce\\Http\\Controllers\\Operator\\PlanController',
            'file' => '/pkg/PlanController.php',
            'extendsParticleBase' => false,
            'resourceKey' => null,
            'actions' => ['index' => 'delta'],
            'crudModels' => ['index' => ['Splicewire\\Beam\\Commerce\\Plan']],
        ]];

        $findings = $this->audit()->suggestFor(
            $controllers,
            ['plans' => true],                                          // hand-wired
            [],                                                         // not front-door-mounted
            ['plans' => true],                                          // registered keys
            ['Splicewire\\Beam\\Commerce\\Plan' => ['plans']],          // registered model map
        );

        $this->assertCount(1, $findings);
        $finding = $findings[0];

        $this->assertFalse($finding->isFixable());
        $this->assertTrue($finding->isAdvisory());
        $this->assertSame('warn', $finding->finding->status->value);
        $this->assertSame('particle.controller-redundant', $finding->finding->check);
        $this->assertStringContainsString('PlanController', $finding->finding->detail);
        $this->assertStringContainsString('[plans]', $finding->finding->detail);
        $this->assertStringContainsString('index', $finding->finding->detail);
        $this->assertStringContainsString("Particle::mount('plans')", $finding->suggestion->summary);
    }

    public function test_the_behavior_path_skips_an_unregistered_model(): void
    {
        $controllers = [[
            'class' => 'App\\Http\\Controllers\\ReportController',
            'file' => '/app/ReportController.php',
            'extendsParticleBase' => false,
            'resourceKey' => null,
            'actions' => ['index' => 'delta'],
            'crudModels' => ['index' => ['App\\Models\\Report']],
        ]];

        // Report is nobody's registered model → no finding.
        $this->assertSame([], $this->audit()->suggestFor(
            $controllers,
            ['reports' => true],
            [],
            ['plans' => true],
            ['Splicewire\\Beam\\Commerce\\Plan' => ['plans']],
        ));
    }

    public function test_the_behavior_path_skips_a_front_door_mounted_or_unwired_key(): void
    {
        $controller = [
            'class' => 'App\\Http\\Controllers\\PlanController',
            'file' => '/app/PlanController.php',
            'extendsParticleBase' => false,
            'resourceKey' => null,
            'actions' => ['index' => 'delta'],
            'crudModels' => ['index' => ['Splicewire\\Beam\\Commerce\\Plan']],
        ];
        $registeredModels = ['Splicewire\\Beam\\Commerce\\Plan' => ['plans']];

        // Key is already mounted through the front door → not a bespoke redundancy.
        $this->assertSame([], $this->audit()->suggestFor(
            [$controller], ['plans' => true], ['plans' => true], ['plans' => true], $registeredModels,
        ));

        // Key not hand-wired anywhere → nothing mounted to collapse.
        $this->assertSame([], $this->audit()->suggestFor(
            [$controller], [], [], ['plans' => true], $registeredModels,
        ));
    }

    public function test_an_acknowledged_behavior_path_controller_downgrades_to_a_pass(): void
    {
        $controllers = [[
            'class' => AcknowledgedShellFixtureController::class,
            'file' => '/app/AcknowledgedShellFixtureController.php',
            'extendsParticleBase' => false,
            'resourceKey' => null,
            'actions' => ['index' => 'delta'],
            'crudModels' => ['index' => ['Splicewire\\Beam\\Commerce\\Plan']],
        ]];

        $findings = $this->audit()->suggestFor(
            $controllers,
            ['plans' => true],
            [],
            ['plans' => true],
            ['Splicewire\\Beam\\Commerce\\Plan' => ['plans']],
        );

        $this->assertCount(1, $findings);
        $this->assertSame('pass', $findings[0]->finding->status->value);
        $this->assertStringContainsString('acknowledged: legacy envelope deltas the declaration cannot express', $findings[0]->finding->detail);
        $this->assertNull($findings[0]->suggestion);
    }

    public function test_an_unregistered_resource_is_not_flagged(): void
    {
        $controllers = [[
            'class' => 'App\\Http\\Controllers\\Api\\V1\\GhostController',
            'file' => '/app/GhostController.php',
            'extendsParticleBase' => true,
            'resourceKey' => 'ghosts',
            'actions' => ['index' => 'passthrough'],
        ]];

        // 'ghosts' is hand-wired but NOT in the registered set → leg 2 fails.
        $this->assertSame([], $this->audit()->suggestFor(
            $controllers,
            ['ghosts' => true],
            ['tags' => true],
            ['agents' => true],
        ));
    }

    public function test_an_already_front_door_mounted_key_is_not_flagged(): void
    {
        $controllers = [[
            'class' => 'App\\Http\\Controllers\\SiloController',
            'file' => '/app/SiloController.php',
            'extendsParticleBase' => true,
            'resourceKey' => 'silos',
            'actions' => ['index' => 'passthrough'],
        ]];

        // 'silos' is registered AND its own key rides the front door → leg 3 fails (not a bespoke hand-wired shell).
        $this->assertSame([], $this->audit()->suggestFor(
            $controllers,
            ['silos' => true],
            ['silos' => true],
            ['silos' => true],
        ));
    }

    // ── AST classification: passthrough vs delta, extends chain, key extraction ────────────────────────

    public function test_it_classifies_a_this_passthrough_action(): void
    {
        $source = $this->controllerSource(<<<'PHP'
            protected function particleResource($r) { return $this->registry->get('agents'); }
            public function create($r) { return $this->createParticle($r); }
            public function show($r, $id) { return parent::show($r, $id); }
        PHP);

        $row = $this->audit()->parseController($source, '/app/AgentController.php');

        $this->assertNotNull($row);
        $this->assertSame('agents', $row['resourceKey']);
        $this->assertSame('passthrough', $row['actions']['create']);
        $this->assertSame('passthrough', $row['actions']['show']);
        // The particleResource() binding override is not an action.
        $this->assertArrayNotHasKey('particleResource', $row['actions']);
    }

    public function test_it_classifies_a_bespoke_body_as_a_delta(): void
    {
        $source = $this->controllerSource(<<<'PHP'
            protected function particleResource($r) { return $this->registry->get('declarations'); }
            public function index($r) {
                $r->validate(['status' => 'sometimes']);
                return ResponseBody::from(['data' => []]);
            }
            public function schema() { return ResponseBody::from(['data' => []]); }
        PHP);

        $row = $this->audit()->parseController($source, '/app/DeclarationController.php');

        $this->assertNotNull($row);
        $this->assertSame('declarations', $row['resourceKey']);
        // Multi-statement guarded body → delta; single non-base-verb return → delta.
        $this->assertSame('delta', $row['actions']['index']);
        $this->assertSame('delta', $row['actions']['schema']);
    }

    public function test_a_class_that_does_not_override_particle_resource_has_a_null_key(): void
    {
        $source = $this->controllerSource(<<<'PHP'
            public function index($r) { return $this->index($r); }
        PHP);

        $row = $this->audit()->parseController($source, '/app/InlineController.php');

        $this->assertNotNull($row);
        $this->assertNull($row['resourceKey']);
    }

    public function test_it_collects_the_models_a_crud_action_body_touches(): void
    {
        // The real PlanController shape: plain base, `index` opens with a Plan::orderBy(...) query.
        $source = <<<'PHP'
        <?php
        namespace Splicewire\Beam\Commerce\Http\Controllers\Operator;

        use Splicewire\Beam\Commerce\Data\PlanData;
        use Splicewire\Beam\Commerce\Plan;
        use Splicewire\Beam\Data\ResponseBody;
        use Splicewire\Beam\Http\Controller;

        class PlanController extends Controller
        {
            public function index()
            {
                $plans = Plan::orderBy('name')->get()->map(fn (Plan $plan) => PlanData::fromPlan($plan));

                return ResponseBody::from(['data' => $plans->all()]);
            }

            public function export()
            {
                return Plan::all();     // non-CRUD verb: bypass-audit territory, not collected here
            }
        }
        PHP;

        $row = $this->audit()->parseController($source, '/pkg/PlanController.php');

        $this->assertNotNull($row);
        $this->assertFalse($row['extendsParticleBase']);
        $this->assertNull($row['resourceKey']);
        $this->assertSame(['index' => ['Splicewire\\Beam\\Commerce\\Plan']], $row['crudModels']);
    }

    public function test_a_crud_action_touching_no_imported_model_collects_nothing(): void
    {
        $source = $this->controllerSource(<<<'PHP'
            public function index($r) { return response()->json([]); }
        PHP);

        $row = $this->audit()->parseController($source, '/app/EmptyController.php');

        $this->assertNotNull($row);
        $this->assertSame([], $row['crudModels']);
    }

    // ── mountedKeysIn(): the front-door mount leg, read off a route file's AST ─────────────────────────

    public function test_it_reads_the_keys_a_route_file_mounts_through_the_front_door(): void
    {
        // One-arg (key defaults to the uri), two-arg (uri and key genuinely diverge), a chained builder,
        // and a named-arg spelling — all four are front-door mounts.
        $source = <<<'PHP'
        <?php
        use Splicewire\Beam\Facades\Particle;
        use Illuminate\Support\Facades\Route;

        Particle::mount('plans');
        Particle::mount('extensions', 'market-extensions')->only(['index', 'show']);
        Particle::mount('/fragments/')->ops(true)->filters(true);
        Particle::mount(uri: 'silos', resourceKey: 'silo-buckets');
        Route::get('leads', [LeadController::class, 'index']);
        PHP;

        $keys = $this->audit()->mountedKeysIn($source);

        sort($keys);
        $this->assertSame(['fragments', 'market-extensions', 'plans', 'silo-buckets'], $keys);
    }

    public function test_it_resolves_an_aliased_particle_facade_import(): void
    {
        // The BeamRouteProxy `RouteFacade` lesson: an alias is invisible to a source-text scan.
        $source = <<<'PHP'
        <?php
        use Splicewire\Beam\Facades\Particle as P;

        P::mount('plans');
        PHP;

        $this->assertSame(['plans'], $this->audit()->mountedKeysIn($source));
    }

    public function test_it_skips_a_mount_whose_key_it_cannot_statically_read(): void
    {
        // A non-literal uri, and an unrelated class's `mount()` — neither is a readable front-door key.
        $source = <<<'PHP'
        <?php
        use Splicewire\Beam\Facades\Particle;

        Particle::mount($resource);
        Disk::mount('plans');
        PHP;

        $this->assertSame([], $this->audit()->mountedKeysIn($source));
    }

    /** Wrap method bodies in a minimal particle-controller class source. */
    private function controllerSource(string $methods): string
    {
        return <<<PHP
        <?php
        namespace App\\Http\\Controllers\\Api\\V1;
        class AgentController extends ParticleController
        {
        {$methods}
        }
        PHP;
    }
}

/** Acknowledgment fixture: a shell whose bespoke shape is a reviewed, class-level-declared decision. */
#[BespokeByDesign(reason: 'legacy envelope deltas the declaration cannot express')]
class AcknowledgedShellFixtureController
{
    public function index(): void {}

    public function create(): void {}

    public function show(): void {}
}
