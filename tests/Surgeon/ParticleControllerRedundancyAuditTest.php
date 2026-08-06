<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Surgeon\Operation\FixableFinding;
use Splicewire\Beam\Surgeon\ParticleControllerRedundancyAudit;

/**
 * The hunt-02 dogfood (refactor-tooling ticket 16): four V1 controllers are thin `ParticleController`
 * CRUD shells on already-registered resources whose peers (`tags`/`media`/`activity`) run controller-free
 * via `Route::particleResource()`. This proves {@see ParticleControllerRedundancyAudit} flags exactly the
 * redundant shells — pure passthrough → fixable collapse; with-delta → advisory naming the blocker — and
 * leaves a non-particle controller, an unregistered resource, and an already-macro-wired key untouched.
 *
 * The `suggestFor()` tests are pure over in-memory fixtures (no registry, no route table, no DB); the
 * `parseController()` / passthrough tests exercise the real AST classification off heredoc source.
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
            ['tags' => true],              // a PEER rides the macro (not the agents key itself)
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

    public function test_a_non_particle_controller_is_not_flagged(): void
    {
        $controllers = [[
            'class' => 'App\\Http\\Controllers\\Api\\LeadController',
            'file' => '/app/LeadController.php',
            'extendsParticleBase' => false,      // leg 1 fails
            'resourceKey' => 'leads',
            'actions' => ['store' => 'passthrough'],
        ]];

        $this->assertSame([], $this->audit()->suggestFor(
            $controllers,
            ['leads' => true],
            ['tags' => true],
            ['leads' => true],
        ));
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

    public function test_an_already_macro_wired_key_is_not_flagged(): void
    {
        $controllers = [[
            'class' => 'App\\Http\\Controllers\\SiloController',
            'file' => '/app/SiloController.php',
            'extendsParticleBase' => true,
            'resourceKey' => 'silos',
            'actions' => ['index' => 'passthrough'],
        ]];

        // 'silos' is registered AND its own key rides the macro → leg 3 fails (not a bespoke hand-wired shell).
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
