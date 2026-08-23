<?php

namespace Splicewire\Beam\Tests\Particle;

use RuntimeException;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * RDU-01 — {@see ParticleResource} now carries the manifest fields (previously on a separate
 * admin-resource subtype, deleted in RDU-07), knows whether it is {@see ParticleResource::isFramed() framed}, and projects
 * into Frame's agnostic {@see ResourceDefinition}. The `$realm` param on the projection is accepted but
 * ignored for now (identical output for every realm).
 */
class ParticleResourceManifestTest extends TestCase
{
    public function test_manifest_fields_round_trip_onto_particle_resource(): void
    {
        $resource = new ParticleResource(
            key: 'widgets',
            backing: 'App\\Models\\Widget',
            data: WidgetGateData::class,
            label: 'Widgets',
            form: 'enriched',
            editData: 'App\\Data\\WidgetEditData',
            policy: 'widget',
            query: 'App\\Queries\\WidgetQuery',
            group: 'Catalog',
            icon: 'cube',
            section: 'operator',
            navOrder: 7,
            routeName: 'widgets.index',
            layout: 'master-detail',
        );

        $this->assertSame('Widgets', $resource->label);
        $this->assertSame('enriched', $resource->form);
        $this->assertSame('App\\Data\\WidgetEditData', $resource->editData);
        $this->assertSame('widget', $resource->policy);
        $this->assertSame('App\\Queries\\WidgetQuery', $resource->query);
        $this->assertSame('Catalog', $resource->group);
        $this->assertSame('cube', $resource->icon);
        $this->assertSame('operator', $resource->section);
        $this->assertSame(7, $resource->navOrder);
        $this->assertSame('widgets.index', $resource->routeName);
        $this->assertSame('master-detail', $resource->layout);
    }

    public function test_a_labelled_resource_is_framed_and_a_bare_one_is_not(): void
    {
        $framed = new ParticleResource(key: 'w', backing: 'App\\Models\\Widget', data: WidgetGateData::class, label: 'Widgets');
        $bare = new ParticleResource(key: 'w', backing: 'App\\Models\\Widget');

        $this->assertTrue($framed->isFramed());
        $this->assertFalse($bare->isFramed());
    }

    public function test_the_explicit_frame_override_wins_over_the_label_heuristic(): void
    {
        // frame:false forces a labelled resource REST-only; frame:true frames a label-less one.
        $labelledButRestOnly = new ParticleResource(key: 'w', backing: 'App\\Models\\Widget', label: 'Widgets', frame: false);
        $bareButFramed = new ParticleResource(key: 'w', backing: 'App\\Models\\Widget', data: WidgetGateData::class, frame: true);

        $this->assertFalse($labelledButRestOnly->isFramed());
        $this->assertTrue($bareButFramed->isFramed());
    }

    public function test_to_resource_definition_projects_a_framed_resource(): void
    {
        $resource = new ParticleResource(
            key: 'widgets',
            backing: 'App\\Models\\Widget',
            data: WidgetGateData::class,
            label: 'Widgets',
            section: 'operator',
            navOrder: 2,
        );

        $def = $resource->toResourceDefinition();

        $this->assertInstanceOf(ResourceDefinition::class, $def);
        $this->assertSame('widgets', $def->key);
        $this->assertSame('model', $def->sourceKind);
        $this->assertSame('App\\Models\\Widget', $def->model);
        $this->assertSame(WidgetGateData::class, $def->data);
        $this->assertSame('Widgets', $def->nav->label);
        $this->assertSame('operator', $def->nav->section);
        $this->assertSame(2, $def->nav->navOrder);
        $this->assertTrue($def->creatable);
    }

    public function test_the_realm_param_is_accepted_but_ignored(): void
    {
        $resource = new ParticleResource(
            key: 'widgets',
            backing: 'App\\Models\\Widget',
            data: WidgetGateData::class,
            label: 'Widgets',
        );

        $default = $resource->toResourceDefinition();
        $forRealm = $resource->toResourceDefinition('some-realm');

        $this->assertEquals($default, $forRealm, 'the projection is identical for every realm (RDU-01)');
    }

    public function test_the_data_guard_fires_for_a_framed_resource_without_a_data_class(): void
    {
        $resource = new ParticleResource(
            key: 'widgets',
            backing: 'App\\Models\\Widget',
            label: 'Widgets', // framed, but no data
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('framed but has no read Data class');

        $resource->toResourceDefinition();
    }
}
