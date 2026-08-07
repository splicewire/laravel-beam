<?php

namespace Splicewire\Beam\Tests\Realm;

use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Frame\AdminResourceRegistry;
use Splicewire\Beam\Particle\ParticleResource as ParticleResourceRuntime;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Realm\RealmResourceOverride;
use Splicewire\Beam\Realm\RealmResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * RDU-03 — the per-realm presentation-override overlay. A realm may present the SAME resource
 * differently (label/group/form/read-only gate) WITHOUT the declaration ever naming a realm. The overlay
 * ships INERT (identity projection in every realm) and is proven here against a fixture resource; real
 * overlays for members/tokens/invitations land in RDU-05.
 */
class RealmResourceOverrideTest extends TestCase
{
    private function registry(?RealmResourceRegistry $overrides = null): AdminResourceRegistry
    {
        $registry = new AdminResourceRegistry($overrides ?? new RealmResourceRegistry(new RealmRegistry));

        $registry->registerDeclaration(new ParticleResourceRuntime(
            key: 'widgets',
            model: 'App\\Models\\Widget',
            data: WidgetGateData::class,
            label: 'Widgets',
            group: 'Workspace',
            section: 'operator',
            navOrder: 3,
        ));

        return $registry;
    }

    public function test_no_overlay_is_identity_in_every_realm(): void
    {
        // (a) With no overlay registered, the projection is IDENTICAL in every realm — the overlay layer
        // is inert. Base (null realm), operator, tenant, and user all yield an equal ResourceDefinition.
        $registry = $this->registry();

        $base = $registry->get('widgets');
        $operator = $registry->get('widgets', 'operator');
        $tenant = $registry->get('widgets', 'tenant');
        $user = $registry->get('widgets', 'user');

        $this->assertEquals($base, $operator);
        $this->assertEquals($base, $tenant);
        $this->assertEquals($base, $user);
        $this->assertSame('Widgets', $user->nav->label);
    }

    public function test_an_overlay_changes_only_its_own_realm(): void
    {
        // (b) An overlay on (widgets, operator) relabels + read-only-gates ONLY the operator manifest; tenant
        // and the realm-agnostic base keep the declaration's presentation untouched.
        $overrides = (new RealmResourceRegistry(new RealmRegistry))->override(
            'widgets',
            'operator',
            new RealmResourceOverride(label: 'Operator Widgets', group: 'Platform', readOnly: true),
        );

        $registry = $this->registry($overrides);

        $operator = $registry->get('widgets', 'operator');
        $this->assertSame('Operator Widgets', $operator->nav->label);
        $this->assertSame('Platform', $operator->nav->group);
        $this->assertFalse($operator->creatable, 'readOnly overlay closes create');
        $this->assertFalse($operator->editable, 'readOnly overlay closes edit');
        $this->assertFalse($operator->deletable, 'readOnly overlay closes delete');

        // Only the operator realm changed — tenant + base are the untouched declaration.
        $tenant = $registry->get('widgets', 'tenant');
        $this->assertSame('Widgets', $tenant->nav->label);
        $this->assertSame('Workspace', $tenant->nav->group);
        $this->assertTrue($tenant->creatable);

        $base = $registry->get('widgets');
        $this->assertSame('Widgets', $base->nav->label);
        $this->assertTrue($base->creatable);
    }

    public function test_an_explicit_gate_wins_over_the_readonly_derivation(): void
    {
        // readOnly derives all three write gates, but an explicit editable/deletable in the SAME overlay
        // wins — a prune-but-not-create/edit presentation.
        $overrides = (new RealmResourceRegistry(new RealmRegistry))->override(
            'widgets',
            'operator',
            new RealmResourceOverride(readOnly: true, deletable: true),
        );

        $def = $this->registry($overrides)->get('widgets', 'operator');

        $this->assertFalse($def->creatable);
        $this->assertFalse($def->editable);
        $this->assertTrue($def->deletable, 'explicit deletable wins over the readOnly derivation');
    }

    public function test_resolution_follows_effective_stack_order(): void
    {
        // (c) The `user` realm composes THROUGH `tenant` (stack: ['tenant']). Overlay resolution walks
        // that same chain bottom→top ([tenant, user]): a tenant overlay applies when presenting in user,
        // and a user overlay — being most-specific — WINS over the tenant one.
        $realms = new RealmRegistry; // ships user.stack === ['tenant'] (collapse_user_realm default true)
        $this->assertSame(['tenant'], $realms->resolve('user')->stack);

        $overrides = (new RealmResourceRegistry($realms))
            ->override('widgets', 'tenant', new RealmResourceOverride(label: 'Tenant Widgets', group: 'Workspace', icon: 'building'))
            ->override('widgets', 'user', new RealmResourceOverride(label: 'My Widgets'));

        $registry = $this->registry($overrides);

        // In the user realm: label comes from the most-specific `user` overlay; group/icon are inherited
        // from the `tenant` overlay lower in the stack (proving the stack IS walked, not just the leaf).
        $user = $registry->get('widgets', 'user');
        $this->assertSame('My Widgets', $user->nav->label, 'user overlay (topmost) wins the label');
        $this->assertSame('building', $user->nav->icon, 'tenant overlay (in the stack) still applies');

        // In the tenant realm itself: only the tenant overlay applies (its own key, no stack).
        $tenant = $registry->get('widgets', 'tenant');
        $this->assertSame('Tenant Widgets', $tenant->nav->label);
    }

    public function test_runtime_fields_never_vary_by_realm(): void
    {
        // (d) An overlay carries PRESENTATION fields only — model/data/sourceKind (the runtime wiring)
        // are identical across realms even when a heavy overlay is applied.
        $overrides = (new RealmResourceRegistry(new RealmRegistry))->override(
            'widgets',
            'operator',
            new RealmResourceOverride(label: 'X', group: 'Y', form: 'bare', layout: 'subnav', readOnly: true),
        );

        $registry = $this->registry($overrides);

        $tenant = $registry->get('widgets', 'tenant');
        $operator = $registry->get('widgets', 'operator');

        foreach ([$tenant, $operator] as $def) {
            $this->assertInstanceOf(ResourceDefinition::class, $def);
            $this->assertSame('App\\Models\\Widget', $def->model, 'model never varies by realm');
            $this->assertSame(WidgetGateData::class, $def->data, 'data class never varies by realm');
            $this->assertSame('model', $def->sourceKind, 'sourceKind never varies by realm');
            $this->assertNull($def->source, 'source never varies by realm');
        }
    }

    public function test_the_registry_reports_whether_an_overlay_applies(): void
    {
        $overrides = (new RealmResourceRegistry(new RealmRegistry))
            ->override('widgets', 'operator', new RealmResourceOverride(label: 'X'));

        $this->assertTrue($overrides->has('widgets', 'operator'));
        $this->assertFalse($overrides->has('widgets', 'tenant'));
        // The `user` realm stacks on `tenant`, so a tenant overlay is reachable from user...
        $overrides->override('gadgets', 'tenant', new RealmResourceOverride(label: 'G'));
        $this->assertTrue($overrides->has('gadgets', 'user'), 'stack chain makes the tenant overlay reachable from user');

        // apply() with a null realm is always identity (the realm-agnostic manifest path).
        $base = $this->registry()->get('widgets');
        $this->assertSame($base, $overrides->apply($base, null));
    }
}
