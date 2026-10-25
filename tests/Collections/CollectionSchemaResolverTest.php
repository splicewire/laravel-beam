<?php

namespace Splicewire\Beam\Tests\Collections;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Collections\CollectionSchemaCatalog;
use Splicewire\Beam\Collections\CollectionSchemaResolver;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Schema\DatabaseSchemaRegistry;
use Splicewire\Beam\Schema\RegistrySchemaTargetResolver;
use Splicewire\Beam\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Runtime addressing follows a stem, never a pinned version or a cached tenant declaration. */
class CollectionSchemaResolverTest extends TestCase
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
        $this->registry = new DatabaseSchemaRegistry;
    }

    private function schema(string $stem, int $version, bool $marked = true): array
    {
        $schema = [
            '$id' => $stem.'/'.$version,
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string']],
        ];
        if ($marked) {
            $schema['x-beam-collection'] = ['label' => 'Articles v'.$version, 'nameField' => 'title'];
        }

        return $schema;
    }

    private function resolver(): CollectionSchemaResolver
    {
        return new CollectionSchemaResolver(new RegistrySchemaTargetResolver($this->registry), $this->registry);
    }

    public function test_latest_version_is_resolved_again_instead_of_cached_on_the_resource(): void
    {
        $stem = 'https://example.test/schemas/article';
        $this->registry->register($this->schema($stem, 1));
        $resolver = $this->resolver();
        $this->assertSame('Articles v1', $resolver->resolve($stem)->label());
        $next = $this->schema($stem, 2);
        $next['properties']['rank'] = ['type' => 'integer', 'x-sort' => ['name' => 'rank', 'default' => true, 'direction' => 'desc']];
        $this->registry->register($next);

        $definition = $resolver->resolve($stem);
        $this->assertSame('Articles v2', $definition->label());
        $this->assertSame(['field' => 'rank', 'direction' => 'desc'], $definition->defaultSort());
    }

    public function test_scoped_resolves_reuse_the_registry_and_a_fresh_scope_reads_again(): void
    {
        $stem = 'https://example.test/schemas/article';
        $this->registry->register($this->schema($stem, 1));
        $this->app->instance(\Schemastud\DataSchemas\Contracts\SchemaRegistry::class, $this->registry);
        $this->app->forgetScopedInstances();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $first = $this->app->make(CollectionSchemaResolver::class);
        $first->resolve($stem);
        $reads = count(\Illuminate\Support\Facades\DB::getQueryLog());
        $this->assertGreaterThan(0, $reads);
        $this->app->make(CollectionSchemaResolver::class)->resolve($stem);
        $this->assertCount($reads, \Illuminate\Support\Facades\DB::getQueryLog());
        $this->app->forgetScopedInstances();
        $next = $this->app->make(CollectionSchemaResolver::class);
        $this->assertNotSame($first, $next);
        $next->resolve($stem);
        $this->assertCount($reads * 2, \Illuminate\Support\Facades\DB::getQueryLog());
    }

    public function test_rebinding_the_host_registry_and_target_does_not_reuse_captured_dependencies(): void
    {
        $stem = 'https://example.test/schemas/article';
        $this->registry->register($this->schema($stem, 1));
        $this->app->instance(\Schemastud\DataSchemas\Contracts\SchemaRegistry::class, $this->registry);
        $this->app->instance(\Splicewire\Beam\Schema\Contracts\SchemaTargetResolver::class, new RegistrySchemaTargetResolver($this->registry));
        $this->app->forgetScopedInstances();
        $first = $this->app->make(CollectionSchemaResolver::class);
        $this->assertSame('Articles v1', $first->resolve($stem)->label());

        $replacement = new DatabaseSchemaRegistry;
        $replacement->register($this->schema($stem, 2));
        $this->app->instance(\Schemastud\DataSchemas\Contracts\SchemaRegistry::class, $replacement);
        $this->app->instance(\Splicewire\Beam\Schema\Contracts\SchemaTargetResolver::class, new RegistrySchemaTargetResolver($replacement));

        $next = $this->app->make(CollectionSchemaResolver::class);
        $this->assertNotSame($first, $next);
        $this->assertSame('Articles v2', $next->resolve($stem)->label());
    }

    public function test_registration_through_another_registry_instance_invalidates_the_scope(): void
    {
        $stem = 'https://example.test/schemas/article';
        $this->registry->register($this->schema($stem, 1));
        $resolver = $this->resolver();
        $this->assertSame('Articles v1', $resolver->resolve($stem)->label());
        (new DatabaseSchemaRegistry)->register($this->schema($stem, 2));
        $this->assertSame('Articles v2', $resolver->resolve($stem)->label());
    }

    public function test_rolled_back_registration_does_not_leave_a_cached_version(): void
    {
        $stem = 'https://example.test/schemas/article';
        $this->registry->register($this->schema($stem, 1));
        $resolver = $this->resolver();
        $this->assertSame('Articles v1', $resolver->resolve($stem)->label());
        \Illuminate\Support\Facades\DB::beginTransaction();
        $this->registry->register($this->schema($stem, 2));
        $this->assertSame('Articles v2', $resolver->resolve($stem)->label());
        \Illuminate\Support\Facades\DB::rollBack();
        $this->assertSame('Articles v1', $resolver->resolve($stem)->label());
    }

    public function test_composed_registry_observes_registration_and_unknown_tiers_stay_fresh(): void
    {
        $stem = 'https://example.test/schemas/article';
        $this->registry->register($this->schema($stem, 1));
        $registry = new \Splicewire\Beam\Schema\BeamSchemaRegistry(['db'], ['db' => fn () => new DatabaseSchemaRegistry]);
        $resolver = new CollectionSchemaResolver(new RegistrySchemaTargetResolver($registry), $registry);
        $this->assertSame('Articles v1', $resolver->resolve($stem)->label());
        $registry->register($this->schema($stem, 2));
        $this->assertSame('Articles v2', $resolver->resolve($stem)->label());
        $unknown = new class($this->registry) implements \Schemastud\DataSchemas\Contracts\EnumeratesVersions, \Schemastud\DataSchemas\Contracts\SchemaRegistry
        {
            public int $reads = 0;

            public function __construct(private DatabaseSchemaRegistry $inner) {}

            public function register(array $schema): void
            {
                $this->inner->register($schema);
            }

            public function get(string $id): ?array
            {
                $this->reads++;

                return $this->inner->get($id);
            }

            public function has(string $id): bool
            {
                return $this->inner->has($id);
            }

            public function ids(): array
            {
                return $this->inner->ids();
            }

            public function versionsFor(string $stem): array
            {
                return $this->inner->versionsFor($stem);
            }
        };
        $composed = new \Splicewire\Beam\Schema\BeamSchemaRegistry(['custom'], ['custom' => fn () => $unknown]);
        $resolver = new CollectionSchemaResolver(new RegistrySchemaTargetResolver($composed), $composed);
        $resolver->resolve($stem);
        $resolver->resolve($stem);
        $this->assertSame(2, $unknown->reads);
    }

    public function test_record_refs_are_exact_versions_and_never_a_child_stem_prefix_match(): void
    {
        $stem = 'https://example.test/schemas/article';
        $this->registry->register($this->schema($stem, 1));
        $this->registry->register($this->schema($stem, 2));
        $this->registry->register($this->schema($stem.'/private', 1));
        $this->assertSame([$stem, $stem.'/1', $stem.'/2'], $this->resolver()->recordRefs($stem));
    }

    public function test_a_latest_unmarked_version_is_not_served_using_an_older_marked_version(): void
    {
        $stem = 'https://example.test/schemas/article';
        $this->registry->register($this->schema($stem, 1));
        $this->registry->register($this->schema($stem, 2, marked: false));
        $this->expectException(NotFoundHttpException::class);
        $this->resolver()->resolve($stem);
    }

    public function test_an_unknown_collection_is_not_served(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->resolver()->resolve('https://example.test/schemas/missing');
    }

    public function test_an_explicit_version_cannot_pin_the_collection_to_old_visibility_rules(): void
    {
        $stem = 'https://example.test/schemas/article';
        $this->registry->register($this->schema($stem, 1));
        $this->expectException(ValidationException::class);
        $this->resolver()->resolve($stem.'/1');
    }

    public function test_an_empty_or_relative_collection_address_is_invalid(): void
    {
        $this->expectException(ValidationException::class);
        $this->resolver()->resolve('article');
    }

    public function test_catalog_lists_only_latest_registered_collection_versions(): void
    {
        $articles = 'https://example.test/schemas/article';
        $people = 'https://example.test/schemas/people';
        $this->registry->register($this->schema($articles, 1));
        $this->registry->register($this->schema($articles, 2));
        $this->registry->register($this->schema($people, 1));
        $this->registry->register($this->schema('https://example.test/schemas/plain', 1, marked: false));

        $catalog = new CollectionSchemaCatalog($this->registry);

        $this->assertSame([
            ['stem' => $articles, 'label' => 'Articles v2'],
            ['stem' => $people, 'label' => 'Articles v1'],
        ], $catalog->addresses());
    }

    public function test_catalog_does_not_expose_an_older_collection_after_latest_version_loses_marker(): void
    {
        $stem = 'https://example.test/schemas/article';
        $this->registry->register($this->schema($stem, 1));
        $this->registry->register($this->schema($stem, 2, marked: false));

        $this->assertSame([], (new CollectionSchemaCatalog($this->registry))->addresses());
    }
}
