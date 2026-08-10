<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Surgeon\TypeScriptShortNameCollisionAudit;

/**
 * Ticket 31 — the cross-package `#[TypeScript]` short-name collision detector, updated ticket 34.
 * `check()` is the pure core: a plain list of {fqn, shortName, name?, location?} rows in, no
 * filesystem/reflection (that lives in `forApp()`'s `collectAnnotatedClasses()`, exercised live via
 * `surgeon:audit`, not unit-tested here).
 */
class TypeScriptShortNameCollisionAuditTest extends TestCase
{
    private function audit(): TypeScriptShortNameCollisionAudit
    {
        return new TypeScriptShortNameCollisionAudit([]);
    }

    public function test_same_short_name_in_different_packages_never_collides_at_native_namespace(): void
    {
        // Ticket 34: no remap left — every class emits at its real native namespace, which can never
        // collide by construction (PHP FQNs are globally unique). A shared short name across packages
        // is not even a candidate collision anymore.
        $findings = $this->audit()->check([
            ['fqn' => 'Splicewire\Tower\Data\Frame\MembershipResourceData', 'shortName' => 'MembershipResourceData'],
            ['fqn' => 'Splicewire\Beam\Accounts\Data\Frame\MembershipResourceData', 'shortName' => 'MembershipResourceData'],
        ]);

        $this->assertSame([], $findings);
    }

    public function test_an_explicit_location_override_can_still_collide_two_different_classes(): void
    {
        // The one remaining real failure mode post-ticket-34: an explicit #[TypeScript(location:)]
        // override bypasses the natural namespace-derived uniqueness, so two unrelated classes can be
        // made to collide if both declare the same override.
        $findings = $this->audit()->check([
            ['fqn' => 'A\Data\WidgetData', 'shortName' => 'WidgetData', 'name' => null, 'location' => ['App', 'Data']],
            ['fqn' => 'B\Data\WidgetData', 'shortName' => 'WidgetData', 'name' => null, 'location' => ['App', 'Data']],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString('WidgetData', $findings[0]->detail);
        $this->assertStringContainsString('A\Data\WidgetData', $findings[0]->detail);
        $this->assertStringContainsString('B\Data\WidgetData', $findings[0]->detail);
    }

    public function test_an_explicit_name_override_can_still_collide_two_different_classes(): void
    {
        // Same failure mode via `name:` instead of `location:` — two classes in the SAME namespace
        // both renamed to the same short name would collide (a PHP redeclaration error either way if
        // truly the same namespace, but defensive for the cross-namespace name-only-override case).
        $findings = $this->audit()->check([
            ['fqn' => 'A\Data\FooData', 'shortName' => 'FooData', 'name' => 'SharedName', 'location' => null],
            ['fqn' => 'A\Data\BarData', 'shortName' => 'BarData', 'name' => 'SharedName', 'location' => null],
        ]);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
    }

    public function test_distinct_short_names_produce_no_finding(): void
    {
        $findings = $this->audit()->check([
            ['fqn' => 'Splicewire\Tower\Data\AuthUserData', 'shortName' => 'AuthUserData'],
            ['fqn' => 'Splicewire\Tower\Data\TowerAuthUserData', 'shortName' => 'TowerAuthUserData'],
        ]);

        $this->assertSame([], $findings);
    }

    public function test_a_single_annotated_class_with_no_twin_produces_no_finding(): void
    {
        // Mirrors ThreadMessageData's resolved state: only ONE of the two same-named classes actually
        // carries #[TypeScript] (the other was de-annotated) — not a collision by this audit's definition.
        $findings = $this->audit()->check([
            ['fqn' => 'Splicewire\Tower\Data\ThreadMessageData', 'shortName' => 'ThreadMessageData'],
        ]);

        $this->assertSame([], $findings);
    }

    public function test_the_same_fqn_appearing_twice_in_the_input_does_not_self_collide(): void
    {
        // A defensive case: if collection ever double-counts a file (e.g. a symlinked directory), the
        // same FQN twice must not read as "two classes."
        $findings = $this->audit()->check([
            ['fqn' => 'Splicewire\Tower\Data\AgentData', 'shortName' => 'AgentData'],
            ['fqn' => 'Splicewire\Tower\Data\AgentData', 'shortName' => 'AgentData'],
        ]);

        $this->assertSame([], $findings);
    }

    public function test_three_classes_sharing_a_short_name_produce_no_finding_at_native_namespace(): void
    {
        // Ticket 34: three DIFFERENT native namespaces, no override — never collides, unlike the
        // pre-ticket-34 flat-path behavior this test used to pin.
        $findings = $this->audit()->check([
            ['fqn' => 'A\Data\ThingData', 'shortName' => 'ThingData'],
            ['fqn' => 'B\Data\ThingData', 'shortName' => 'ThingData'],
            ['fqn' => 'C\Data\ThingData', 'shortName' => 'ThingData'],
        ]);

        $this->assertSame([], $findings);
    }

    public function test_three_classes_sharing_an_override_are_reported_together_not_per_pair(): void
    {
        $findings = $this->audit()->check([
            ['fqn' => 'A\Data\ThingData', 'shortName' => 'ThingData', 'name' => null, 'location' => ['App', 'Data']],
            ['fqn' => 'B\Data\ThingData', 'shortName' => 'ThingData', 'name' => null, 'location' => ['App', 'Data']],
            ['fqn' => 'C\Data\ThingData', 'shortName' => 'ThingData', 'name' => null, 'location' => ['App', 'Data']],
        ]);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('3 #[TypeScript]-annotated classes', $findings[0]->detail);
    }

    public function test_an_empty_input_set_produces_no_findings(): void
    {
        $this->assertSame([], $this->audit()->check([]));
    }

    public function test_manifest_driven_registered_classes_never_reach_this_audit(): void
    {
        // Ticket 29 item C's ManifestDrivenTransformedProvider force-emits classes that do NOT carry
        // #[TypeScript] at all (e.g. LlmKeySecretData) — this audit's collection step only picks up
        // reflection-visible #[TypeScript] attributes, so a manifest-driven-only class is structurally
        // invisible to it by construction, not something that could crash it. Nothing to assert on the
        // pure core (there's no row to construct for an unannotated class) — this test documents the
        // invariant; live coverage that the real scan doesn't choke on such a repo is `surgeon:audit`.
        $this->assertTrue(true);
    }
}
