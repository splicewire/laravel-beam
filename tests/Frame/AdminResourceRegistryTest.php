<?php

namespace Splicewire\Beam\Tests\Frame;

use InvalidArgumentException;
use Schemastud\Frame\Registry\NavMetadata;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Frame\AdminResourceRegistry;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Particle\ParticleResource as ParticleResourceRuntime;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * RDU-02 — the registry no longer FREEZES one {@see ResourceDefinition} per key at registration. It stores
 * the resource DECLARATION (a {@see ParticleResourceRuntime}) and projects it PER-REALM at manifest-build
 * time via {@see ParticleResourceRuntime::toResourceDefinition()}. Discovery is unified onto
 * `#[ParticleResource]` (RDU-07 removed the legacy dual-read); a source-backed (union) resource — which
 * the model-required attribute can't express — registers imperatively via {@see AdminResourceRegistry::register()}.
 * A framed resource appears in the manifest exactly as before; a REST-only one does not — no behavior change.
 */
class AdminResourceRegistryTest extends TestCase
{
    public function test_it_stores_a_declaration_and_projects_at_build_time(): void
    {
        $registry = new AdminResourceRegistry;

        $declaration = new ParticleResourceRuntime(
            key: 'widgets',
            model: 'App\\Models\\Widget',
            data: WidgetGateData::class,
            label: 'Widgets',
            section: 'admin',
            navOrder: 3,
        );

        $registry->registerDeclaration($declaration);

        // The manifest is projected from the stored declaration — a ResourceDefinition, freshly built.
        $def = $registry->get('widgets');

        $this->assertInstanceOf(ResourceDefinition::class, $def);
        $this->assertSame('widgets', $def->key);
        $this->assertSame('App\\Models\\Widget', $def->model);
        $this->assertSame('Widgets', $def->nav->label);
        $this->assertSame('admin', $def->nav->section);
        $this->assertSame(3, $def->nav->navOrder);
    }

    public function test_it_projects_per_realm_at_build_not_frozen_at_registration(): void
    {
        $registry = new AdminResourceRegistry;
        $registry->registerDeclaration(new ParticleResourceRuntime(
            key: 'widgets',
            model: 'App\\Models\\Widget',
            data: WidgetGateData::class,
            label: 'Widgets',
        ));

        // Each build recomputes the projection from the declaration — two calls yield equal-but-distinct
        // ResourceDefinition objects (not one frozen instance handed back), proving the realm seam is live.
        $first = $registry->get('widgets');
        $second = $registry->get('widgets');

        $this->assertNotSame($first, $second, 'each build reprojects the declaration (not a frozen def)');
        $this->assertEquals($first, $second, 'the projection is realm-identical today (RDU-02)');
    }

    public function test_a_unified_particle_resource_class_registers_via_register_class(): void
    {
        $registry = new AdminResourceRegistry;
        $registry->registerClass(FixtureFramedParticleResource::class);

        $this->assertTrue($registry->has('framed-particle'));

        $def = $registry->get('framed-particle');
        $this->assertSame('framed-particle', $def->key);
        $this->assertSame('Framed Particle', $def->nav->label);
        $this->assertSame(WidgetGateData::class, $def->data);
    }

    public function test_a_rest_only_particle_resource_does_not_appear_in_the_manifest(): void
    {
        $registry = new AdminResourceRegistry;
        // A label-less #[ParticleResource] is REST-only (not framed) — it has no Frame projection.
        $registry->registerClass(FixtureRestOnlyParticleResource::class);

        $this->assertFalse($registry->has('rest-only'), 'a REST-only resource is not a manifest resource');
        $this->assertSame([], $registry->all(), 'and it never appears in the served manifest');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('REST-only');
        $registry->get('rest-only');
    }

    public function test_a_framed_resource_appears_in_the_manifest_and_a_rest_only_one_does_not(): void
    {
        $registry = new AdminResourceRegistry;
        $registry->registerClass(FixtureFramedParticleResource::class);
        $registry->registerClass(FixtureRestOnlyParticleResource::class);

        $manifest = $registry->all();

        $this->assertCount(1, $manifest, 'only the framed resource is served');
        $this->assertSame('framed-particle', $manifest[0]->key);
    }

    public function test_a_source_backed_union_resource_registers_imperatively_as_a_frozen_definition(): void
    {
        // The model-required #[ParticleResource] attribute can't express a source-backed union resource,
        // so it registers imperatively as a raw ResourceDefinition (RDU-07) — served as-is, realm-invariant.
        $registry = new AdminResourceRegistry;
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

        $registry->register($definition);

        $this->assertTrue($registry->has('union-admin'));

        $def = $registry->get('union-admin');
        $this->assertSame('service', $def->sourceKind);
        $this->assertSame('App\\Sources\\ReviewQueue', $def->source);
        $this->assertFalse($def->creatable);
        $this->assertFalse($def->deletable);
        $this->assertFalse($def->editable);
    }

    public function test_the_imperative_register_escape_hatch_serves_a_raw_definition(): void
    {
        $registry = new AdminResourceRegistry;
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

        $registry->register($definition);

        // A raw ResourceDefinition escape hatch is served as-is (realm-invariant).
        $this->assertSame($definition, $registry->get('imperative'));
        $this->assertSame([$definition], $registry->all());
    }

    public function test_an_unannotated_class_throws(): void
    {
        $registry = new AdminResourceRegistry;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not annotated with #[ParticleResource]');
        $registry->registerClass(FixtureUnannotated::class);
    }
}

#[ParticleResource(
    key: 'framed-particle',
    model: 'App\\Models\\Widget',
    data: WidgetGateData::class,
    label: 'Framed Particle',
)]
class FixtureFramedParticleResource {}

#[ParticleResource(key: 'rest-only', model: 'App\\Models\\Widget')]
class FixtureRestOnlyParticleResource {}

class FixtureUnannotated {}
