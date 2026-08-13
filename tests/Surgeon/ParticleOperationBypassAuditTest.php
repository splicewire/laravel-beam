<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Surgeon\Operation\FixableFinding;
use Splicewire\Beam\Particle\Attributes\BespokeByDesign;
use Splicewire\Beam\Surgeon\ParticleOperationBypassAudit;

/**
 * The declaration-drift-audit dogfood (ticket 03): audiostud's `StudioController::preview` reads/writes
 * `Composition`, the exact model the registered `songs` particle resource targets, entirely outside
 * `ParticleOperationRegistry`. This proves {@see ParticleOperationBypassAudit} flags exactly that shape —
 * always advisory, never a mechanical fix — and leaves a CRUD verb, an unregistered model, and an
 * already-registered operation untouched.
 *
 * The `suggestFor()` tests are pure over in-memory fixtures (no registry, no route table, no DB); the
 * `parseController()` tests exercise the real AST classification off heredoc source.
 */
class ParticleOperationBypassAuditTest extends TestCase
{
    private function audit(): ParticleOperationBypassAudit
    {
        // dirs unused by suggestFor()/parseController(); no registries (pure-unit path).
        return new ParticleOperationBypassAudit('/nonexistent', '/nonexistent');
    }

    // ── suggestFor(): the four-leg invariant ────────────────────────────────────────────────────────

    public function test_a_hand_wired_action_on_a_registered_models_bare_model_is_an_advisory_bypass(): void
    {
        $controllers = [[
            'class' => 'App\\Http\\Controllers\\StudioController',
            'file' => '/app/StudioController.php',
            'actions' => ['preview' => ['App\\Models\\Composition']],
        ]];

        $findings = $this->audit()->suggestFor(
            $controllers,
            ['App\\Http\\Controllers\\StudioController::preview' => true],
            ['App\\Models\\Composition' => ['songs']],
            [],
        );

        $this->assertCount(1, $findings);
        /** @var FixableFinding $finding */
        $finding = $findings[0];

        $this->assertFalse($finding->isFixable());
        $this->assertTrue($finding->isAdvisory());
        $this->assertSame('warn', $finding->finding->status->value);
        $this->assertSame('particle.operation-bypass', $finding->finding->check);
        $this->assertNotNull($finding->suggestion);
        $this->assertSame('splicewire/laravel-beam', $finding->suggestion->owningPackage);
        $this->assertStringContainsString('songs', $finding->suggestion->summary);
        $this->assertStringContainsString('preview', $finding->suggestion->summary);
    }

    public function test_a_standard_crud_verb_is_not_flagged(): void
    {
        $controllers = [[
            'class' => 'App\\Http\\Controllers\\SongController',
            'file' => '/app/SongController.php',
            'actions' => ['show' => ['App\\Models\\Composition']],
        ]];

        // leg 3: 'show' is a base CRUD verb — ParticleControllerRedundancyAudit's territory.
        $this->assertSame([], $this->audit()->suggestFor(
            $controllers,
            ['App\\Http\\Controllers\\SongController::show' => true],
            ['App\\Models\\Composition' => ['songs']],
            [],
        ));
    }

    public function test_an_action_not_hand_wired_by_a_bespoke_route_is_not_flagged(): void
    {
        $controllers = [[
            'class' => 'App\\Http\\Controllers\\StudioController',
            'file' => '/app/StudioController.php',
            'actions' => ['preview' => ['App\\Models\\Composition']],
        ]];

        // leg 1: no matching hand-wired route entry.
        $this->assertSame([], $this->audit()->suggestFor(
            $controllers,
            [],
            ['App\\Models\\Composition' => ['songs']],
            [],
        ));
    }

    public function test_an_action_touching_an_unregistered_model_is_not_flagged(): void
    {
        $controllers = [[
            'class' => 'App\\Http\\Controllers\\StudioController',
            'file' => '/app/StudioController.php',
            'actions' => ['generate' => ['App\\Models\\Draft']],
        ]];

        // leg 2: 'Draft' is not the registered model of any resource.
        $this->assertSame([], $this->audit()->suggestFor(
            $controllers,
            ['App\\Http\\Controllers\\StudioController::generate' => true],
            ['App\\Models\\Composition' => ['songs']],
            [],
        ));
    }

    public function test_an_action_already_covered_by_a_registered_operation_is_not_flagged(): void
    {
        $controllers = [[
            'class' => 'App\\Http\\Controllers\\StudioController',
            'file' => '/app/StudioController.php',
            'actions' => ['preview' => ['App\\Models\\Composition']],
        ]];

        // leg 4: 'songs:preview' is already a registered ParticleOperation.
        $this->assertSame([], $this->audit()->suggestFor(
            $controllers,
            ['App\\Http\\Controllers\\StudioController::preview' => true],
            ['App\\Models\\Composition' => ['songs']],
            ['songs:preview' => true],
        ));
    }

    /**
     * declaration-drift-audit ticket 04's live audiostud run caught this for real: `Composition` is the
     * registered model of BOTH `songs` (owner write) and `listen-songs` (public read) — picking either
     * silently would sometimes name the WRONG resource in the suggestion (e.g. telling a caller to hang a
     * write op off the public-read resource). Two-plus resource keys on one model must surface as an
     * explicit ambiguity, not a guess.
     */
    public function test_a_model_shared_by_multiple_resources_is_flagged_as_ambiguous_not_guessed(): void
    {
        $controllers = [[
            'class' => 'App\\Http\\Controllers\\StudioController',
            'file' => '/app/StudioController.php',
            'actions' => ['preview' => ['App\\Models\\Composition']],
        ]];

        $findings = $this->audit()->suggestFor(
            $controllers,
            ['App\\Http\\Controllers\\StudioController::preview' => true],
            ['App\\Models\\Composition' => ['songs', 'listen-songs']],
            [],
        );

        $this->assertCount(1, $findings);
        /** @var FixableFinding $finding */
        $finding = $findings[0];

        $this->assertTrue($finding->isAdvisory());
        $this->assertStringContainsString('MULTIPLE particle resources', $finding->finding->detail);
        $this->assertStringContainsString('songs, listen-songs', $finding->finding->detail);
        $this->assertStringContainsString('Ambiguous', $finding->finding->detail);
        $this->assertNotNull($finding->suggestion);
        $this->assertStringContainsString('resolve which one owns this action', $finding->suggestion->summary);
    }

    /**
     * A registered operation on ANY one of several shared-model resource keys already covers the action —
     * leg 4 must check every candidate, not just a single arbitrarily-chosen one.
     */
    public function test_an_action_covered_by_an_operation_on_any_shared_resource_is_not_flagged(): void
    {
        $controllers = [[
            'class' => 'App\\Http\\Controllers\\StudioController',
            'file' => '/app/StudioController.php',
            'actions' => ['preview' => ['App\\Models\\Composition']],
        ]];

        $this->assertSame([], $this->audit()->suggestFor(
            $controllers,
            ['App\\Http\\Controllers\\StudioController::preview' => true],
            ['App\\Models\\Composition' => ['songs', 'listen-songs']],
            ['listen-songs:preview' => true],
        ));
    }

    // ── #[BespokeByDesign] acknowledgment: WARN downgrades to a Pass that still carries the reason ────

    public function test_an_acknowledged_bypass_downgrades_to_a_pass_surfacing_the_reason(): void
    {
        $controllers = [[
            'class' => AcknowledgedBypassFixtureController::class,
            'file' => '/app/AcknowledgedBypassFixtureController.php',
            'actions' => ['nestedRead' => ['App\\Models\\Composition']],
        ]];

        $findings = $this->audit()->suggestFor(
            $controllers,
            [AcknowledgedBypassFixtureController::class.'::nestedRead' => true],
            ['App\\Models\\Composition' => ['songs']],
            [],
        );

        $this->assertCount(1, $findings);
        /** @var FixableFinding $finding */
        $finding = $findings[0];

        $this->assertSame('pass', $finding->finding->status->value);
        $this->assertSame('particle.operation-bypass', $finding->finding->check);
        $this->assertStringContainsString('acknowledged: nested subject the particleOp grammar cannot carry', $finding->finding->detail);
        $this->assertNull($finding->suggestion);
        $this->assertFalse($finding->isFixable());
        $this->assertFalse($finding->isAdvisory());
    }

    public function test_a_class_level_acknowledgment_covers_every_bypassing_action(): void
    {
        $controllers = [[
            'class' => WhollyBespokeFixtureController::class,
            'file' => '/app/WhollyBespokeFixtureController.php',
            'actions' => ['redirect' => ['App\\Models\\Composition'], 'callback' => ['App\\Models\\Composition']],
        ]];

        $findings = $this->audit()->suggestFor(
            $controllers,
            [
                WhollyBespokeFixtureController::class.'::redirect' => true,
                WhollyBespokeFixtureController::class.'::callback' => true,
            ],
            ['App\\Models\\Composition' => ['songs']],
            [],
        );

        $this->assertCount(2, $findings);
        foreach ($findings as $finding) {
            $this->assertSame('pass', $finding->finding->status->value);
            $this->assertStringContainsString('acknowledged: the whole flow is transport-bespoke', $finding->finding->detail);
            $this->assertNull($finding->suggestion);
        }
    }

    public function test_an_unacknowledged_action_on_an_acknowledging_class_still_warns_when_method_targeted(): void
    {
        // The fixture class carries NO class-level attribute; only nestedRead is acknowledged — its
        // sibling action stays a WARN (acknowledgment is per-site, never a blanket mute).
        $controllers = [[
            'class' => AcknowledgedBypassFixtureController::class,
            'file' => '/app/AcknowledgedBypassFixtureController.php',
            'actions' => ['plainAction' => ['App\\Models\\Composition']],
        ]];

        $findings = $this->audit()->suggestFor(
            $controllers,
            [AcknowledgedBypassFixtureController::class.'::plainAction' => true],
            ['App\\Models\\Composition' => ['songs']],
            [],
        );

        $this->assertCount(1, $findings);
        $this->assertSame('warn', $findings[0]->finding->status->value);
        $this->assertTrue($findings[0]->isAdvisory());
    }

    // ── AST classification: touched models, imports, CRUD-verb/constructor exclusion ──────────────────

    public function test_it_detects_a_static_call_on_an_imported_model_by_hint_method(): void
    {
        $source = $this->controllerSource(<<<'PHP'
            public function preview($request) {
                $composition = Composition::query()->whereKey($request->id)->first();
                return $composition;
            }
        PHP);

        $row = $this->audit()->parseController($source, '/app/StudioController.php');

        $this->assertNotNull($row);
        $this->assertSame(['App\\Models\\Composition'], $row['actions']['preview']);
    }

    public function test_it_ignores_a_static_call_whose_method_is_not_a_model_touch_hint(): void
    {
        $source = $this->controllerSource(<<<'PHP'
            public function show($request) {
                return Inertia::render('studio');
            }
        PHP);

        $row = $this->audit()->parseController($source, '/app/StudioController.php');

        $this->assertNotNull($row);
        $this->assertSame([], $row['actions']['show']);
    }

    public function test_it_excludes_the_constructor_from_actions(): void
    {
        $source = $this->controllerSource(<<<'PHP'
            public function __construct(private SplicewireClient $client) {}
            public function generate($request) { return Composition::create([]); }
        PHP);

        $row = $this->audit()->parseController($source, '/app/StudioController.php');

        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('__construct', $row['actions']);
        $this->assertSame(['App\\Models\\Composition'], $row['actions']['generate']);
    }

    /** Wrap method bodies in a minimal controller class source with the imports this suite relies on. */
    private function controllerSource(string $methods): string
    {
        return <<<PHP
        <?php
        namespace App\\Http\\Controllers;
        use App\\Models\\Composition;
        use App\\Services\\Splicewire\\SplicewireClient;
        use Inertia\\Inertia;
        class StudioController extends Controller
        {
        {$methods}
        }
        PHP;
    }
}

/** Acknowledgment fixture: ONE action acknowledged at the method target, its sibling deliberately not. */
class AcknowledgedBypassFixtureController
{
    #[BespokeByDesign(reason: 'nested subject the particleOp grammar cannot carry')]
    public function nestedRead(): void {}

    public function plainAction(): void {}
}

/** Acknowledgment fixture: the CLASS target covering every hand-wired action on it. */
#[BespokeByDesign(reason: 'the whole flow is transport-bespoke')]
class WhollyBespokeFixtureController
{
    public function redirect(): void {}

    public function callback(): void {}
}
