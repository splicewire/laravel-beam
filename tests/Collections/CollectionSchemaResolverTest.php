<?php

namespace Splicewire\Beam\Tests\Collections;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
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
}
