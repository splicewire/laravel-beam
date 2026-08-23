<?php

namespace Splicewire\Beam\Tests\Particle;

use InvalidArgumentException;
use Schemastud\Frame\Registry\NavMetadata;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Particle\ParticleResource as ParticleResourceRuntime;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * RDU-02 — the registry no longer FREEZES one {@see ResourceDefinition} per key at registration. It stores
 * the resource DECLARATION (a {@see ParticleResourceRuntime}) and projects it PER-REALM at manifest-build
 * time via {@see ParticleResourceRuntime::toResourceDefinition()}. Discovery is unified onto
 * `#[ParticleResource]` (RDU-07 removed the legacy dual-read); a source-backed (union) resource — which
 * the model-required attribute can't express — registers imperatively via {@see ParticleResourceRegistry::registerDefinition()}.
 * A framed resource appears in the manifest exactly as before; a REST-only one does not — no behavior change.
 *
 * The merge (retired the parallel `AdminResourceRegistry`) split this class' single `get()`/`all()` pair
 * into a REST tier ({@see ParticleResourceRegistry::get()} — returns the {@see ParticleResourceRuntime}
 * declaration) and a differently-named Frame-projection tier ({@see ParticleResourceRegistry::definition()}/
 * {@see ParticleResourceRegistry::definitions()} — returns {@see ResourceDefinition}), so these tests now
 * assert against the projection tier where the retired class asserted against `get()`/`all()`.
 */
class ParticleResourceRegistryTest extends TestCase
{
    public function test_it_stores_a_declaration_and_projects_at_build_time(): void
    {
        $registry = new ParticleResourceRegistry;

        $declaration = new ParticleResourceRuntime(
            key: 'widgets',
            backing: 'App\\Models\\Widget',
            data: WidgetGateData::class,
            label: 'Widgets',
            section: 'operator',
            navOrder: 3,
        );

        $registry->register($declaration);

        // The manifest is projected from the stored declaration — a ResourceDefinition, freshly built.
        $def = $registry->definition('widgets');

        $this->assertInstanceOf(ResourceDefinition::class, $def);
        $this->assertSame('widgets', $def->key);
        $this->assertSame('App\\Models\\Widget', $def->model);
        $this->assertSame('Widgets', $def->nav->label);
        $this->assertSame('operator', $def->nav->section);
        $this->assertSame(3, $def->nav->navOrder);
    }

    public function test_it_projects_per_realm_at_build_not_frozen_at_registration(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResourceRuntime(
            key: 'widgets',
            backing: 'App\\Models\\Widget',
            data: WidgetGateData::class,
            label: 'Widgets',
        ));

        // Each build recomputes the projection from the declaration — two calls yield equal-but-distinct
        // ResourceDefinition objects (not one frozen instance handed back), proving the realm seam is live.
        $first = $registry->definition('widgets');
        $second = $registry->definition('widgets');

        $this->assertNotSame($first, $second, 'each build reprojects the declaration (not a frozen def)');
        $this->assertEquals($first, $second, 'the projection is realm-identical today (RDU-02)');
    }

    public function test_a_unified_particle_resource_class_registers_via_register_class(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->registerClass(FixtureFramedParticleResource::class);

        $this->assertTrue($registry->hasFramedResource('framed-particle'));

        $def = $registry->definition('framed-particle');
        $this->assertSame('framed-particle', $def->key);
        $this->assertSame('Framed Particle', $def->nav->label);
        $this->assertSame(WidgetGateData::class, $def->data);
    }

    public function test_a_rest_only_particle_resource_does_not_appear_in_the_manifest(): void
    {
        $registry = new ParticleResourceRegistry;
        // A label-less #[ParticleResource] is REST-only (not framed) — it has no Frame projection.
        $registry->registerClass(FixtureRestOnlyParticleResource::class);

        $this->assertFalse($registry->hasFramedResource('rest-only'), 'a REST-only resource is not a manifest resource');
        $this->assertSame([], $registry->definitions(), 'and it never appears in the served manifest');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('REST-only');
        $registry->definition('rest-only');
    }

    public function test_a_framed_resource_appears_in_the_manifest_and_a_rest_only_one_does_not(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->registerClass(FixtureFramedParticleResource::class);
        $registry->registerClass(FixtureRestOnlyParticleResource::class);

        $manifest = $registry->definitions();

        $this->assertCount(1, $manifest, 'only the framed resource is served');
        $this->assertSame('framed-particle', $manifest[0]->key);
    }

    public function test_a_source_backed_union_resource_registers_imperatively_as_a_frozen_definition(): void
    {
        // The model-required #[ParticleResource] attribute can't express a source-backed union resource,
        // so it registers imperatively as a raw ResourceDefinition (RDU-07) — served as-is, realm-invariant.
        $registry = new ParticleResourceRegistry;
        $definition = new ResourceDefinition(
            key: 'union-admin',
            sourceKind: 'service',
            model: null,
            source: 'App\\Sources\\ReviewQueue',
            data: WidgetGateData::class,
            creatable: false,
            query: null,
            editData: null,
            policy: null,
            form: 'bare',
            nav: new NavMetadata(label: 'Union Admin'),
            deletable: false,
            editable: false,
        );

        $registry->registerDefinition($definition);

        $this->assertTrue($registry->hasFramedResource('union-admin'));

        $def = $registry->definition('union-admin');
        $this->assertSame('service', $def->sourceKind);
        $this->assertSame('App\\Sources\\ReviewQueue', $def->source);
        $this->assertFalse($def->creatable);
        $this->assertFalse($def->deletable);
        $this->assertFalse($def->editable);
    }

    public function test_the_imperative_register_escape_hatch_serves_a_raw_definition(): void
    {
        $registry = new ParticleResourceRegistry;
        $definition = new ResourceDefinition(
            key: 'imperative',
            sourceKind: 'model',
            model: 'App\\Models\\Widget',
            source: null,
            data: WidgetGateData::class,
            creatable: true,
            query: null,
            editData: null,
            policy: null,
            form: 'bare',
            nav: new NavMetadata(label: 'Imperative'),
        );

        $registry->registerDefinition($definition);

        // A raw ResourceDefinition escape hatch is served as-is (realm-invariant).
        $this->assertSame($definition, $registry->definition('imperative'));
        $this->assertSame([$definition], $registry->definitions());
    }

    public function test_an_unannotated_class_throws(): void
    {
        $registry = new ParticleResourceRegistry;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not annotated with #[ParticleResource]');
        $registry->registerClass(FixtureUnannotated::class);
    }
}

#[ParticleResource(
    key: 'framed-particle',
    backing: 'App\\Models\\Widget',
    data: WidgetGateData::class,
    label: 'Framed Particle',
)]
class FixtureFramedParticleResource {}

#[ParticleResource(key: 'rest-only', backing: 'App\\Models\\Widget')]
class FixtureRestOnlyParticleResource {}

class FixtureUnannotated {}
