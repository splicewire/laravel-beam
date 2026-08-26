<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\WidgetGateData;
use Splicewire\Beam\Tests\TestCase;

/**
 * The attributed REST/op discovery — the runtime twin of #[ParticleResource] discovery. Asserts a
 * `#[ParticleResource]` / `#[ParticleOp]` Data class reflects into the two particle registries with its
 * scalar declaration AND its closure hooks wired from `public static` convention methods.
 */
class AttributedParticleDiscoveryTest extends TestCase
{
    private function discovery(): AttributedParticleDiscovery
    {
        return new AttributedParticleDiscovery(
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
        );
    }

    public function test_a_resource_attribute_registers_its_scalar_declaration(): void
    {
        $this->discovery()->registerClass(FixtureLyricResource::class);

        $resource = $this->app->make(ParticleResourceRegistry::class)->get('library-lyrics');

        $this->assertSame('library-lyrics', $resource->key);
        $this->assertSame(FixtureModel::class, $resource->backing);
        $this->assertSame(FixtureModel::class, $resource->modelClass());
        $this->assertSame(FixtureInput::class, $resource->input);
        $this->assertSame(['tags'], $resource->includes);
        $this->assertFalse($resource->filterable);
        $this->assertSame(50, $resource->perPage);
    }

    public function test_data_defaults_to_the_annotated_class_when_absent(): void
    {
        $this->discovery()->registerClass(FixtureLyricResource::class);

        $this->assertSame(
            FixtureLyricResource::class,
            $this->app->make(ParticleResourceRegistry::class)->get('library-lyrics')->data,
        );
    }

    public function test_convention_static_methods_are_wired_as_closures(): void
    {
        $this->discovery()->registerClass(FixtureLyricResource::class);

        $resource = $this->app->make(ParticleResourceRegistry::class)->get('library-lyrics');

        // Present convention methods → non-null closures.
        $this->assertNotNull($resource->scope);
        $this->assertNotNull($resource->prepare);
        $this->assertNotNull($resource->project);
        // afterWrite is NOT declared on the fixture → stays null.
        $this->assertNull($resource->afterWrite);
    }

    public function test_the_attributes_manifest_fields_are_read_into_the_declaration(): void
    {
        // RDU-02: the #[ParticleResource] attribute now carries the manifest fields, so a resource is
        // fully describable declaratively. They must round-trip onto the runtime declaration.
        $resource = AttributedParticleDiscovery::resourceFromAttribute(FixtureFramedResource::class);

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
        $this->assertTrue($resource->readOnly);
        $this->assertTrue($resource->isFramed(), 'a labelled resource is framed');
    }

    public function test_a_resource_without_a_scope_method_leaves_the_hook_null(): void
    {
        $this->discovery()->registerClass(FixtureBareResource::class);

        $resource = $this->app->make(ParticleResourceRegistry::class)->get('bare');

        $this->assertNull($resource->scope);
        $this->assertNull($resource->prepare);
        $this->assertNull($resource->project);
    }

    public function test_an_op_attribute_registers_with_its_handle_wired(): void
    {
        $this->discovery()->registerClass(FixtureRegenerateOp::class);

        $op = $this->app->make(ParticleOperationRegistry::class)->get('library-lyrics', 'regenerate');

        $this->assertSame('library-lyrics.regenerate', $op->key());
        $this->assertSame(OperationKind::Task, $op->kind);
        $this->assertSame('update', $op->ability);
        $this->assertNotNull($op->respond);
    }

    public function test_an_op_without_a_handle_method_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must declare a');

        $this->discovery()->registerClass(FixtureHandlelessOp::class);
    }

    public function test_a_class_with_none_of_the_particle_attributes_throws(): void
    {
        // The message enumerates the attributes rather than saying "neither", because the set is three
        // now — `#[ParticleRelative]` joined it under api-surface-coherence ticket 50 — and a caller who
        // mis-spelled one needs to be told what the legal set IS.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('carries none of #[ParticleResource], #[ParticleOp], #[ParticleRelative]');

        $this->discovery()->registerClass(FixtureModel::class);
    }

    public function test_an_op_carries_its_declared_input_and_output_slots_through_discovery(): void
    {
        $this->discovery()->registerClass(FixtureDeclaredOp::class);

        $op = $this->app->make(ParticleOperationRegistry::class)->get('library-lyrics', 'declared');

        $this->assertSame(FixtureInput::class, $op->input);
        $this->assertSame(FixtureOutput::class, $op->output);
    }

    public function test_a_stream_ops_output_slot_is_an_event_name_map(): void
    {
        $this->discovery()->registerClass(FixtureStreamOp::class);

        $op = $this->app->make(ParticleOperationRegistry::class)->get('library-lyrics', 'watch');

        // A stream emits discrete typed events under distinct wire names; one event name may cover several
        // payload variants, discriminated further by a DTO field. Collapsing to one class loses that.
        $this->assertSame([
            'run_status' => [FixtureOutput::class],
            'node_status' => [FixtureOutput::class, FixtureInput::class],
        ], $op->output);
    }

    public function test_an_op_declaring_neither_slot_still_registers(): void
    {
        $this->discovery()->registerClass(FixtureRegenerateOp::class);

        $op = $this->app->make(ParticleOperationRegistry::class)->get('library-lyrics', 'regenerate');

        $this->assertNull($op->input);
        $this->assertNull($op->output);
    }

    public function test_an_event_map_on_a_non_stream_kind_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('event-name map');

        new ParticleOperation(
            resource: 'library-lyrics',
            name: 'bad',
            kind: OperationKind::Write,
            model: FixtureModel::class,
            handle: fn () => null,
            output: ['run_status' => [FixtureOutput::class]],
        );
    }

    public function test_a_single_output_class_on_a_stream_kind_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('event-name map');

        new ParticleOperation(
            resource: 'library-lyrics',
            name: 'bad-stream',
            kind: OperationKind::Stream,
            model: FixtureModel::class,
            handle: fn () => null,
            output: FixtureOutput::class,
        );
    }

    public function test_an_inline_operation_carries_the_slots_identically_to_an_attributed_one(): void
    {
        // Discovery and manual registration must AGREE — an inline op is the same runtime object, so a
        // declaration made either way has to be readable by the same consumers.
        $inline = new ParticleOperation(
            resource: 'library-lyrics',
            name: 'inline',
            kind: OperationKind::Write,
            model: FixtureModel::class,
            handle: fn () => null,
            input: FixtureInput::class,
            output: FixtureOutput::class,
        );

        $this->discovery()->registerClass(FixtureDeclaredOp::class);
        $attributed = $this->app->make(ParticleOperationRegistry::class)->get('library-lyrics', 'declared');

        $this->assertSame($attributed->input, $inline->input);
        $this->assertSame($attributed->output, $inline->output);
    }

    public function test_discovery_is_idempotent_by_key(): void
    {
        $registry = $this->app->make(ParticleResourceRegistry::class);

        $this->discovery()->discover(classes: [FixtureLyricResource::class]);
        $this->discovery()->discover(classes: [FixtureLyricResource::class]);

        // Re-registering the same key overwrites, never duplicates — still resolvable.
        $this->assertSame('library-lyrics', $registry->get('library-lyrics')->key);
    }
}

class FixtureModel {}

class FixtureInput {}

#[ParticleResource(
    key: 'library-lyrics',
    backing: FixtureModel::class,
    input: FixtureInput::class,
    includes: ['tags'],
    filterable: false,
    perPage: 50,
)]
class FixtureLyricResource
{
    public static function scope(Builder $q): Builder
    {
        return $q;
    }

    public static function prepare($model, $input, $actor): void {}

    public static function project($model)
    {
        return $model;
    }
}

#[ParticleResource(key: 'bare', backing: FixtureModel::class)]
class FixtureBareResource {}

#[ParticleResource(
    key: 'framed-widgets',
    backing: FixtureModel::class,
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
    readOnly: true,
)]
class FixtureFramedResource {}

#[ParticleOp(
    resource: 'library-lyrics',
    name: 'regenerate',
    kind: OperationKind::Task,
    model: FixtureModel::class,
    ability: 'update',
)]
class FixtureRegenerateOp
{
    public static function handle($model, Request $request, $actor)
    {
        return null;
    }

    public static function respond($model): array
    {
        return ['data' => null];
    }
}

#[ParticleOp(
    resource: 'library-lyrics',
    name: 'broken',
    kind: OperationKind::Write,
    model: FixtureModel::class,
)]
class FixtureHandlelessOp {}

class FixtureOutput {}

#[ParticleOp(
    resource: 'library-lyrics',
    name: 'declared',
    kind: OperationKind::Write,
    model: FixtureModel::class,
    input: FixtureInput::class,
    output: FixtureOutput::class,
)]
class FixtureDeclaredOp
{
    public static function handle($model, Request $request, $actor)
    {
        return null;
    }
}

#[ParticleOp(
    resource: 'library-lyrics',
    name: 'watch',
    kind: OperationKind::Stream,
    model: FixtureModel::class,
    output: [
        'run_status' => [FixtureOutput::class],
        'node_status' => [FixtureOutput::class, FixtureInput::class],
    ],
)]
class FixtureStreamOp
{
    public static function handle($model, Request $request, $actor, $emit)
    {
        return null;
    }
}
