<?php

namespace Splicewire\Beam\Tests\Particle;

use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleResource as ParticleResourceAttribute;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * `createAffordance` — the last leg of a slot whose frame tier was landed complete and inert.
 *
 * `Schemastud\Frame\Registry\ResourceDefinition` has carried the slot since `f85ebac`, and frame's
 * `ListShell`/`DefaultToolbar` have honoured the resolved value since `58bf7df`. Nothing emitted it,
 * because beam is what builds every `ResourceDefinition` in this estate and beam's declaration had no
 * such field. These tests assert the three links that close the wire: the runtime
 * {@see ParticleResource}, its attribute twin, and the projection between them.
 *
 * ⚠️ The load-bearing assertion in this file is the LAST pair, and it is the reason the slot is worth
 * a test at all. `createAffordance` and `creatable` are INDEPENDENT axes:
 *
 *   - A creatable resource may declare `'host'` — all four opted-in surfaces (`threads`, `agents`,
 *     `hooks`, `context-scopes`) are `creatable: true` and draw their own button.
 *   - A NON-creatable resource may still have a real, working create. `tenants` is exactly that:
 *     `readOnly: true` alongside `editData: CreateTenantData`, because its create is a schema surface
 *     submitting to the REST provisioning endpoint rather than a Frame write. `fragments` is the same
 *     shape.
 *
 * So the two must never be ANDed on either side: ANDing them into `creatable` deletes a live button
 * behind a green suite, and letting the presentation slot widen `creatable` re-opens a closed write
 * path. Frame resolves them in ONE place with `creatable` winning; beam's job is only to carry the
 * declared value there untouched, and to leave `readOnly` alone while doing it.
 */
class CreateAffordanceDeclarationTest extends TestCase
{
    private function resource(array $overrides = []): ParticleResource
    {
        return new ParticleResource(...array_merge([
            'key' => 'widgets',
            'backing' => 'App\\Models\\Widget',
            'data' => WidgetGateData::class,
            'label' => 'Widgets',
        ], $overrides));
    }

    public function test_the_slot_defaults_to_frame_so_every_existing_declaration_is_unchanged(): void
    {
        $this->assertSame('frame', $this->resource()->createAffordance);
        $this->assertSame('frame', $this->resource()->toResourceDefinition()->createAffordance);
    }

    public function test_a_declared_host_affordance_reaches_frames_resource_definition(): void
    {
        $definition = $this->resource(['createAffordance' => 'host'])->toResourceDefinition();

        $this->assertInstanceOf(ResourceDefinition::class, $definition);
        $this->assertSame('host', $definition->createAffordance);
        $this->assertSame('host', $definition->resolvedCreateAffordance());
    }

    public function test_the_attribute_twin_carries_the_slot_and_defaults_to_frame(): void
    {
        $this->assertSame('frame', (new ParticleResourceAttribute(key: 'w', backing: 'App\\Models\\Widget'))->createAffordance);
        $this->assertSame('host', (new ParticleResourceAttribute(key: 'w', backing: 'App\\Models\\Widget', createAffordance: 'host'))->createAffordance);
    }

    public function test_discovery_carries_the_attributes_declared_affordance_onto_the_runtime_resource(): void
    {
        $discovery = new AttributedParticleDiscovery(
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
        );

        $discovery->registerClass(FixtureHostAffordanceResource::class);

        $resource = $this->app->make(ParticleResourceRegistry::class)->get('fixture-host-affordance');

        $this->assertSame('host', $resource->createAffordance);
        $this->assertSame('host', $resource->toResourceDefinition()->createAffordance);
    }

    /**
     * Beam's OWN `hooks` declaration, read off the shipped attribute rather than restated — the one
     * of the four opted-in resources that lives in this repo. Its create mints a secret returned
     * exactly once, which frame's generic create form cannot express.
     */
    public function test_beams_hooks_resource_declares_the_host_affordance_and_stays_creatable(): void
    {
        $resource = AttributedParticleDiscovery::resourceFromAttribute(HookData::class);

        $this->assertSame('hooks', $resource->key);
        $this->assertSame('host', $resource->createAffordance);
        $this->assertFalse($resource->readOnly);
        $this->assertTrue($resource->toResourceDefinition()->creatable);
    }

    // ── The two directions that must NOT be folded ───────────────────────────────────────────────

    public function test_declaring_host_does_not_close_the_write_path(): void
    {
        $definition = $this->resource(['createAffordance' => 'host'])->toResourceDefinition();

        // `creatable` still answers "may this be created at all", and nothing about WHERE the button
        // lives may change it. Same for the sibling gates it is derived alongside.
        $this->assertTrue($definition->creatable);
        $this->assertTrue($definition->editable);
        $this->assertTrue($definition->deletable);
    }

    public function test_declaring_frame_does_not_re_open_a_closed_write_path(): void
    {
        // The `tenants` shape: readOnly (⇒ not creatable through Frame) yet carrying a real create
        // that is not a Frame write. The presentation slot is carried verbatim...
        $definition = $this->resource([
            'readOnly' => true,
            'editData' => 'App\\Data\\CreateWidgetData',
            'createAffordance' => 'frame',
        ])->toResourceDefinition();

        $this->assertSame('frame', $definition->createAffordance);
        $this->assertFalse($definition->creatable);
        // ...and frame's resolution folds `creatable` in with `creatable` WINNING, so the value the
        // shell reads is 'host'. A layout slot must never manufacture a write gate.
        $this->assertSame('host', $definition->resolvedCreateAffordance());
        // The escape-hatch create DTO survives untouched — that is the whole reason this shape exists.
        $this->assertSame('App\\Data\\CreateWidgetData', $definition->editData);
    }
}

#[ParticleResourceAttribute(
    key: 'fixture-host-affordance',
    backing: CreateAffordanceFixtureModel::class,
    label: 'Fixture host affordance',
    createAffordance: 'host',
)]
class FixtureHostAffordanceResource {}

class CreateAffordanceFixtureModel {}
