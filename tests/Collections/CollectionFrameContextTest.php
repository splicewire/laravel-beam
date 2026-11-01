<?php

namespace Splicewire\Beam\Tests\Collections;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Schemastud\Frame\Contracts\ResourceContextContributor;
use Schemastud\Frame\Registry\ContextManifest;
use Splicewire\Beam\Collections\CollectionSchemaResolver;
use Splicewire\Beam\Data\RecordData;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Schema\DatabaseSchemaRegistry;
use Splicewire\Beam\Schema\RegistrySchemaTargetResolver;
use Splicewire\Beam\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CollectionFrameContextTest extends TestCase
{
    private DatabaseSchemaRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create(Beam::table('schemas'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_id')->unique();
            $table->string('schema_name');
            $table->integer('version');
            $table->string('fingerprint');
            $table->json('artifact');
            $table->timestamps();
        });
        Schema::create(Beam::table('particles'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_ref');
            $table->string('schema_id')->nullable();
            $table->string('migration_status')->nullable();
            $table->string('source_tier')->default('local');
            $table->json('payload')->nullable();
            $table->timestamps();
        });
        $this->registry = new DatabaseSchemaRegistry;
    }

    public function test_frame_manifest_uses_the_selected_collection_schema_before_and_after_records_exist(): void
    {
        $stem = 'https://example.test/schemas/receipt-note';
        $schema = [
            '$id' => $stem.'/1',
            'type' => 'object',
            'x-beam-collection' => ['label' => 'Receipt Notes', 'nameField' => 'label'],
            'properties' => [
                'label' => ['type' => 'string', 'title' => 'Receipt label'],
                'priority' => [
                    'type' => 'string',
                    'enum' => ['low', 'high'],
                    'x-filter' => true,
                    'x-stud-widget-contexts' => [
                        'list-column' => ['participates' => true, 'widget' => 'badge', 'label' => 'Urgency', 'sort' => 7],
                    ],
                ],
                'quantity' => [
                    'type' => 'integer',
                    'x-stud-widget-contexts' => [
                        'list-column' => ['participates' => false],
                        'edit' => ['participates' => true, 'widget' => 'number'],
                    ],
                ],
            ],
        ];
        $this->registry->register($schema);
        $request = Request::create('/api/v1/stud/frame/manifest?collection='.urlencode($stem));
        $collectionSchemas = new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry);
        $this->app->instance(Request::class, $request);
        $this->app->instance(CollectionSchemaResolver::class, $collectionSchemas);
        $frame = new ContextManifest($this->app->make(ResourceContextContributor::class));

        $emptyCollection = $frame->forResource(RecordData::class, key: 'records');

        $this->assertSame(0, DB::table(Beam::table('particles'))->count());
        $this->assertSame(
            ['participates' => true, 'sort' => 0, 'label' => 'Receipt label'],
            $emptyCollection['byNode']['fields.label']['list-column'],
        );
        $this->assertSame(
            ['participates' => true, 'widget' => 'badge', 'label' => 'Urgency', 'sort' => 7],
            $emptyCollection['byNode']['fields.priority']['list-column'],
        );
        $this->assertSame(
            ['participates' => false],
            $emptyCollection['byNode']['fields.quantity']['list-column'],
        );
        $this->assertSame(
            ['participates' => true, 'widget' => 'number'],
            $emptyCollection['byNode']['fields.quantity']['edit'],
        );
        $this->assertTrue($collectionSchemas->resolve($stem)->properties()['priority']['x-filter']);

        DB::table(Beam::table('particles'))->insert([
            'id' => '00000000-0000-7000-8000-000000000004',
            'schema_ref' => $stem.'/1',
            'schema_id' => $stem.'/1',
            'migration_status' => 'current',
            'payload' => json_encode(['label' => 'Receipt Second Collection Row', 'priority' => 'high', 'quantity' => 7]),
        ]);

        $populatedCollection = $frame->forResource(RecordData::class, key: 'records');

        $this->assertSame(1, DB::table(Beam::table('particles'))->count());
        $this->assertSame($emptyCollection['byNode'], $populatedCollection['byNode']);

        $this->app->instance(Request::class, Request::create('/api/v1/stud/frame/manifest'));
        $this->app->forgetScopedInstances();
        $unaddressed = new ContextManifest($this->app->make(ResourceContextContributor::class));
        $this->assertArrayNotHasKey(
            'fields.label',
            $unaddressed->forResource(RecordData::class, key: 'records')['byNode'],
        );
    }

    public function test_frame_manifest_collection_address_uses_the_existing_typed_query_contract(): void
    {
        $request = Request::create('/api/v1/stud/frame/manifest', 'GET', [
            'collection' => ['https://example.test/schemas/receipt-note'],
        ]);
        $resolver = new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry);
        $this->app->instance(Request::class, $request);
        $this->app->instance(CollectionSchemaResolver::class, $resolver);
        $manifest = new ContextManifest($this->app->make(ResourceContextContributor::class));

        $this->expectException(ValidationException::class);
        $manifest->forResource(RecordData::class, key: 'records');
    }

    public function test_frame_manifest_hides_an_unknown_or_inactive_collection_with_not_found(): void
    {
        $stem = 'https://example.test/schemas/inactive-collection';
        $request = Request::create('/api/v1/stud/frame/manifest?collection='.urlencode($stem));
        $resolver = new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry);
        $this->app->instance(Request::class, $request);
        $this->app->instance(CollectionSchemaResolver::class, $resolver);

        $manifest = new ContextManifest($this->app->make(ResourceContextContributor::class));

        $this->expectException(NotFoundHttpException::class);
        $manifest->forResource(RecordData::class, key: 'records');
    }

    public function test_frame_manifest_hides_a_registered_schema_that_is_not_a_collection(): void
    {
        $stem = 'https://example.test/schemas/not-a-collection';
        $this->registry->register([
            '$id' => $stem.'/1',
            'type' => 'object',
            'properties' => ['label' => ['type' => 'string']],
        ]);
        $request = Request::create('/api/v1/stud/frame/manifest?collection='.urlencode($stem));
        $resolver = new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry);
        $this->app->instance(Request::class, $request);
        $this->app->instance(CollectionSchemaResolver::class, $resolver);

        $manifest = new ContextManifest($this->app->make(ResourceContextContributor::class));

        $this->expectException(NotFoundHttpException::class);
        $manifest->forResource(RecordData::class, key: 'records');
    }
}
