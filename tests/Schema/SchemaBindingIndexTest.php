<?php

namespace Splicewire\Beam\Tests\Schema;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\RegistryKey;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\Attributes\ParticleResource as ResourceAttribute;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Schema\SchemaBindingIndex;
use Splicewire\Beam\Tests\TestCase;

class SchemaBindingIndexTest extends TestCase
{
    private const STEM = 'https://beam.test/schemas/content/article';

    private function resource(string $key = 'articles', ?string $ref = self::STEM): ParticleResource
    {
        return new ParticleResource(
            key: $key,
            backing: 'App\\Models\\Article',
            data: BindingArticleData::class,
            input: false,
            readOnly: true,
            schemaRef: $ref,
        );
    }

    public function test_versioned_schema_lookup_uses_the_declared_stem_without_frame_or_key_guessing(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register($this->resource(ref: self::STEM.'/1'));
        $index = new SchemaBindingIndex($registry);

        $this->assertSame([], $registry->definitions());
        $this->assertSame(BindingArticleData::class, $index->dataClassFor(self::STEM.'/7'));
        $this->assertSame(BindingArticleData::class, $index->dataClassFor(self::STEM));
        $this->assertNull($index->dataClassFor('articles'));
        $this->assertNull($index->dataClassFor('https://other.test/schemas/content/article/1'));
    }

    public function test_attribute_registration_carries_the_binding(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->registerClass(BindingArticleData::class);

        $this->assertSame(self::STEM.'/1', $registry->get('articles')->schemaRef);
        $this->assertSame(BindingArticleData::class, (new SchemaBindingIndex($registry))->dataClassFor(self::STEM.'/2'));
    }

    public function test_two_explicit_claims_on_one_stem_fail_atomically_even_with_the_same_data_class(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register($this->resource());
        try {
            $registry->register($this->resource('other', self::STEM.'/2'));
            $this->fail('A duplicate schema claim must not register.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('articles', $exception->getMessage());
            $this->assertStringContainsString('other', $exception->getMessage());
        }
        $this->assertFalse($registry->has('other'));
        $this->assertSame(BindingArticleData::class, (new SchemaBindingIndex($registry))->dataClassFor(self::STEM));
    }

    public function test_replacing_a_resource_updates_the_derived_binding_and_releases_the_old_one(): void
    {
        $registry = new ParticleResourceRegistry;
        $index = new SchemaBindingIndex($registry);
        $registry->register($this->resource());
        $registry->register($this->resource(ref: 'https://beam.test/schemas/content/note/1'));

        $this->assertNull($index->dataClassFor(self::STEM));
        $this->assertSame(BindingArticleData::class, $index->dataClassFor('https://beam.test/schemas/content/note'));
        $registry->register($this->resource('replacement'));
        $this->assertSame(BindingArticleData::class, $index->dataClassFor(self::STEM));
    }

    public function test_implicit_data_identity_can_be_shared_and_does_not_claim_a_schema_name(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register($this->resource('first', null));
        $registry->register($this->resource('second', null));
        $index = new SchemaBindingIndex($registry);

        $this->assertSame(BindingArticleData::class, $index->dataClassFor(BindingArticleData::class));
        $this->assertNull($index->dataClassFor(self::STEM));
    }

    public function test_a_rejected_replacement_preserves_both_existing_bindings(): void
    {
        $registry = new ParticleResourceRegistry;
        $first = $this->resource();
        $second = $this->resource('notes', 'https://beam.test/schemas/content/note');
        $registry->register($first);
        $registry->register($second);
        try {
            $registry->register($this->resource('articles', $second->schemaRef));
            $this->fail('A colliding replacement must not register.');
        } catch (InvalidArgumentException) {
            $this->assertSame($first, $registry->get('articles'));
            $this->assertSame($second, $registry->get('notes'));
        }
        $this->assertSame([], $registry->superseded('articles'));
    }

    public function test_lookup_tracks_authorization_and_collision_checks_ignore_actor_visibility(): void
    {
        $registry = new ParticleResourceRegistry;
        $authorizer = new class implements Authorizer
        {
            public bool $allowed = false;

            public function allows(string $ability, RegistryKey $key): bool
            {
                return $this->allowed;
            }
        };
        $registry->authorizeWith($authorizer);
        $registry->register('articles', $this->resource(), ability: 'articles.read');
        $index = new SchemaBindingIndex($registry);

        $this->assertNull($index->dataClassFor(self::STEM));
        $authorizer->allowed = true;
        $this->assertSame(BindingArticleData::class, $index->dataClassFor(self::STEM));
        $authorizer->allowed = false;
        $this->assertNull($index->dataClassFor(self::STEM));
        $this->expectException(InvalidArgumentException::class);
        $registry->register($this->resource('hidden-collision'));
    }

    private function recordResource(string $key, string $data = BindingArticleData::class, ?string $ref = null, string $model = BindingRecord::class): ParticleResource
    {
        return new ParticleResource(key: $key, backing: $model, data: $data, input: false, readOnly: true, schemaRef: $ref);
    }

    public function test_record_identity_precedes_model_inference_and_normalizes_versions(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register($this->recordResource('first'));
        $registry->register($this->recordResource('second', BindingOtherData::class, self::STEM, Model::class));
        $index = new SchemaBindingIndex($registry);
        $this->assertSame([], $registry->definitions());
        $record = new BindingRecord(['schema_ref' => self::STEM.'/7']);
        $this->assertSame(BindingOtherData::class, $index->dataClassForRecord($record));
        $record->schema_ref = 'https://unknown.test/no-binding';
        $this->assertNull($index->dataClassForRecord($record));
        $record->schema_ref = BindingArticleData::class;
        $this->assertSame(BindingArticleData::class, $index->dataClassForRecord($record));
        $this->assertSame(BindingOtherData::class, $index->dataClassForRecord(new RecordTypeBindingRecord(['type_identity' => self::STEM.'/2'])));
    }

    public function test_identity_priority_and_absent_sources_are_explicit(): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register($this->recordResource('article'));
        $registry->register($this->recordResource('other', BindingOtherData::class, self::STEM));
        $index = new SchemaBindingIndex($registry);
        $record = new MethodBindingRecord(['binding_identity' => BindingArticleData::class, 'schema_ref' => self::STEM, 'type_identity' => 'unknown']);
        $this->assertSame(BindingArticleData::class, $index->dataClassForRecord($record));
        $record->binding_identity = null;
        $this->assertSame(BindingOtherData::class, $index->dataClassForRecord($record));
        $record->binding_identity = '';
        $record->schema_ref = '';
        $record->type_identity = BindingArticleData::class;
        $this->assertSame(BindingArticleData::class, $index->dataClassForRecord($record));
    }

    public static function invalidIdentities(): array
    {
        $cases = [];
        foreach (['binding_identity', 'schema_ref', 'type_identity'] as $source) {
            foreach (['false' => false, 'zero' => 0, 'array' => [], 'whitespace' => '  '] as $label => $value) {
                $cases[$source.' '.$label] = [$source, $value];
            }
        }
        return $cases;
    }

    #[DataProvider('invalidIdentities')]
    public function test_invalid_present_identity_cannot_fall_through(string $source, mixed $value): void
    {
        $registry = new ParticleResourceRegistry;
        $registry->register($this->recordResource('article'));
        $record = new MethodBindingRecord;
        if ($source === 'binding_identity') {
            $record->schema_ref = BindingArticleData::class;
        } elseif ($source === 'schema_ref') {
            $record->type_identity = BindingArticleData::class;
        }
        $record->setAttribute($source, $value);
        $this->expectException(InvalidArgumentException::class);
        (new SchemaBindingIndex($registry))->dataClassForRecord($record);
    }

    public function test_plain_model_candidates_are_deduplicated_live_and_never_first_match(): void
    {
        $registry = new ParticleResourceRegistry;
        $index = new SchemaBindingIndex($registry);
        $record = new BindingRecord;
        $this->assertNull($index->dataClassForRecord($record));
        $registry->register($this->recordResource('first'));
        $this->assertSame(BindingArticleData::class, $index->dataClassForRecord($record));
        $registry->register($this->recordResource('second'));
        $this->assertSame(BindingArticleData::class, $index->dataClassForRecord($record));
        $registry->register($this->recordResource('second', BindingOtherData::class));
        try {
            $index->dataClassForRecord($record);
            $this->fail('Different read shapes must be ambiguous.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('first', $exception->getMessage());
            $this->assertStringContainsString('second', $exception->getMessage());
        }
    }

    public function test_inherited_model_ambiguity_is_stable_in_both_registration_orders(): void
    {
        $messages = [];
        foreach ([false, true] as $reverse) {
            $registry = new ParticleResourceRegistry;
            $resources = [$this->recordResource('z-child', BindingOtherData::class, model: ChildBindingRecord::class), $this->recordResource('a-base')];
            foreach ($reverse ? array_reverse($resources) : $resources as $resource) {
                $registry->register($resource);
            }
            try {
                (new SchemaBindingIndex($registry))->dataClassForRecord(new ChildBindingRecord);
                $this->fail('An exact model match must not hide the inherited competing shape.');
            } catch (RuntimeException $exception) {
                $messages[] = $exception->getMessage();
                foreach ([ChildBindingRecord::class, 'a-base', 'z-child', BindingArticleData::class, BindingOtherData::class] as $name) {
                    $this->assertStringContainsString($name, $exception->getMessage());
                }
            }
        }
        $this->assertSame($messages[0], $messages[1]);
    }

    public function test_record_lookup_observes_live_authorization_without_hidden_fallback_or_names(): void
    {
        $registry = new ParticleResourceRegistry;
        $authorizer = new class implements Authorizer {
            public bool $allowed = false;
            public function allows(string $ability, RegistryKey $key): bool { return $this->allowed; }
        };
        $registry->authorizeWith($authorizer);
        $registry->register($this->recordResource('visible'));
        $registry->register('hidden-secret', $this->recordResource('hidden-secret', BindingOtherData::class, self::STEM), ability: 'secret');
        $index = new SchemaBindingIndex($registry);
        $record = new BindingRecord;
        $this->assertSame(BindingArticleData::class, $index->dataClassForRecord($record));
        $this->assertNull($index->dataClassForRecord(new BindingRecord(['schema_ref' => self::STEM])));
        $authorizer->allowed = true;
        $this->assertSame(BindingOtherData::class, $index->dataClassForRecord(new BindingRecord(['schema_ref' => self::STEM])));
        try {
            $index->dataClassForRecord($record);
            $this->fail('Visible competing shape must be ambiguous.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('hidden-secret', $exception->getMessage());
        }
        $authorizer->allowed = false;
        $registry->register($this->recordResource('visible-other', BindingOtherData::class));
        try {
            $index->dataClassForRecord($record);
            $this->fail('Visible competing shape must remain ambiguous.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('hidden-secret', $exception->getMessage());
        }
    }

    public function test_explicit_binding_requires_a_read_data_class(): void
    {
        $resource = $this->resource();
        $resource->data = null;
        $this->expectException(InvalidArgumentException::class);
        (new ParticleResourceRegistry)->register($resource);
    }

    public function test_empty_explicit_binding_is_not_an_implicit_binding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ParticleResourceRegistry)->register($this->resource(ref: ''));
    }
}

#[ResourceAttribute(
    key: 'articles',
    backing: 'App\\Models\\Article',
    input: false,
    readOnly: true,
    schemaRef: 'https://beam.test/schemas/content/article/1',
)]
class BindingArticleData extends Data {}

class BindingOtherData extends Data {}
class BindingRecord extends Model { protected $guarded = []; }
class ChildBindingRecord extends BindingRecord {}
class RecordTypeBindingRecord extends BindingRecord
{
    public function recordType(): mixed { return $this->getAttribute('type_identity'); }
}
class MethodBindingRecord extends RecordTypeBindingRecord
{
    public function schemaBinding(): mixed { return $this->getAttribute('binding_identity'); }
}
