<?php

namespace Splicewire\Beam\Tests\Particle;

use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleResource as ParticleResourceAttribute;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * `singularLabel` — a declared word that reached only the docs generator.
 *
 * It has existed on {@see ParticleResource} since the Scribe title strategies needed it (`media`
 * singularizes to "Medium", which is the entire reason the slot is there) and it stopped at
 * `ParticleTitleStrategy` / `ParticleUrlParameterStrategy`. Frame's list toolbar — the one surface
 * in the estate that puts a noun after the word "New" — never saw it, and rendered the raw resource
 * KEY instead: *"New scaffold-packs"*. Two flagship pages re-implemented the whole Toolbar slot to
 * work around a word the declaration was already carrying.
 *
 * This file asserts the one projection line that closes that wire, and the two things it must NOT do.
 *
 * ⚠️ The word is not a gate, in either direction. It cannot suppress a create and it cannot resurrect
 * one — `creatable` and `createAffordance` keep answering that alone. This is the same hazard
 * `CreateAffordanceDeclarationTest` pins one slot over, and the reason both files assert the
 * neighbouring axis is untouched rather than just asserting their own.
 */
class SingularLabelReachesFrameTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function resource(array $overrides = []): ParticleResource
    {
        return new ParticleResource(...array_merge([
            'key' => 'widgets',
            'backing' => 'App\\Models\\Widget',
            'data' => WidgetGateData::class,
            'label' => 'Widgets',
        ], $overrides));
    }

    public function test_the_slot_defaults_to_empty_so_every_existing_declaration_is_unchanged(): void
    {
        $this->assertSame('', $this->resource()->singularLabel);
        $this->assertSame('', $this->resource()->toResourceDefinition()->singularLabel);
    }

    public function test_a_declared_singular_reaches_frames_resource_definition(): void
    {
        $definition = $this->resource(['singularLabel' => 'Media'])->toResourceDefinition();

        $this->assertInstanceOf(ResourceDefinition::class, $definition);
        $this->assertSame('Media', $definition->singularLabel);
        $this->assertSame('Media', $definition->resolvedSingularLabel());
    }

    /**
     * The undeclared majority, which is where the visible defect lived: frame now says
     * "New widget" instead of "New widgets", off the plural label alone.
     */
    public function test_an_undeclared_resource_inflects_its_plural_label_rather_than_its_key(): void
    {
        $definition = $this->resource(['key' => 'scaffold-packs', 'label' => 'Scaffold packs'])
            ->toResourceDefinition();

        $this->assertSame('Scaffold pack', $definition->resolvedSingularLabel());
    }

    /**
     * The attribute twin. A resource declared as `#[ParticleResource]` must project the same slot,
     * or the declaration is legal, documented, and inert on exactly the surfaces that use it.
     */
    public function test_the_attribute_twin_carries_the_slot(): void
    {
        $this->assertSame('', (new ParticleResourceAttribute(key: 'w', backing: 'App\\Models\\Widget'))->singularLabel);
        $this->assertSame(
            'Media',
            (new ParticleResourceAttribute(key: 'media', backing: 'App\\Models\\Medium', singularLabel: 'Media'))->singularLabel,
        );
    }

    /**
     * A live declaration rather than a fixture — `hooks` is the estate's one resource that already
     * declared the slot, for the docs generator, before anything else read it.
     */
    public function test_beams_own_hooks_declaration_now_reaches_the_definition(): void
    {
        $resource = AttributedParticleDiscovery::resourceFromAttribute(HookData::class);

        $this->assertSame('Hook', $resource->singularLabel);
        $this->assertSame('Hook', $resource->toResourceDefinition()->resolvedSingularLabel());
    }

    /**
     * Both directions of the hazard, on one declaration: a resource that declares a display noun and
     * is read-only must stay read-only, and one that declares a noun and is creatable must stay
     * creatable. The word participates in neither resolution.
     */
    public function test_the_word_neither_opens_nor_closes_a_create(): void
    {
        $open = $this->resource(['singularLabel' => 'Medium'])->toResourceDefinition();
        $this->assertTrue($open->creatable);
        $this->assertSame('frame', $open->resolvedCreateAffordance());

        $closed = $this->resource(['singularLabel' => 'Medium', 'readOnly' => true])
            ->toResourceDefinition();
        $this->assertFalse($closed->creatable);
        $this->assertSame('host', $closed->resolvedCreateAffordance());
    }
}
