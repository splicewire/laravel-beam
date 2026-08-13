<?php

namespace Splicewire\Beam\Tests\Particle;

use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Particle\Attributes\BespokeByDesign;

/**
 * The acknowledgment attribute's resolution rules ({@see BespokeByDesign::on()}): a METHOD-level
 * declaration wins for its own action, a CLASS-level one covers every action (the fallback), and both
 * the undeclared and the non-autoloadable (pure-unit fixture) cases resolve to null — the exact
 * un-acknowledged path the doctrine audits keep WARNing on.
 */
class BespokeByDesignTest extends TestCase
{
    public function test_a_method_level_declaration_resolves_for_that_method(): void
    {
        $acknowledged = BespokeByDesign::on(BespokeFixtureController::class, 'nestedRead');

        $this->assertNotNull($acknowledged);
        $this->assertSame('nested {parent}/{id} subject the particleOp grammar cannot carry', $acknowledged->reason);
    }

    public function test_a_class_level_declaration_covers_a_method_without_its_own(): void
    {
        $acknowledged = BespokeByDesign::on(WholeShellFixtureController::class, 'index');

        $this->assertNotNull($acknowledged);
        $this->assertSame('legacy envelope shell kept whole', $acknowledged->reason);
    }

    public function test_the_method_level_declaration_wins_over_the_class_level_one(): void
    {
        $acknowledged = BespokeByDesign::on(WholeShellFixtureController::class, 'special');

        $this->assertSame('its own sharper reason', $acknowledged?->reason);
    }

    public function test_a_class_level_declaration_resolves_with_no_method(): void
    {
        $this->assertSame(
            'legacy envelope shell kept whole',
            BespokeByDesign::on(WholeShellFixtureController::class)?->reason,
        );
    }

    public function test_an_undeclared_class_and_method_resolve_to_null(): void
    {
        $this->assertNull(BespokeByDesign::on(BespokeFixtureController::class));
        $this->assertNull(BespokeByDesign::on(BespokeFixtureController::class, 'plainAction'));
    }

    public function test_a_non_autoloadable_class_resolves_to_null(): void
    {
        $this->assertNull(BespokeByDesign::on('App\\Http\\Controllers\\NotARealController', 'anything'));
    }
}

/** Method-level acknowledgment only — the class itself stays un-acknowledged. */
class BespokeFixtureController
{
    #[BespokeByDesign(reason: 'nested {parent}/{id} subject the particleOp grammar cannot carry')]
    public function nestedRead(): void {}

    public function plainAction(): void {}
}

/** Class-level acknowledgment covering every action, one method carrying its own sharper reason. */
#[BespokeByDesign(reason: 'legacy envelope shell kept whole')]
class WholeShellFixtureController
{
    public function index(): void {}

    #[BespokeByDesign(reason: 'its own sharper reason')]
    public function special(): void {}
}
