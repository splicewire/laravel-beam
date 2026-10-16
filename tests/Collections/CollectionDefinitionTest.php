<?php

namespace Splicewire\Beam\Tests\Collections;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Collections\CollectionDefinition;

class CollectionDefinitionTest extends TestCase
{
    private function schema(): array
    {
        return [
            '$id' => 'https://example.test/schemas/article/2',
            'type' => 'object',
            'x-beam-collection' => ['label' => 'Articles', 'nameField' => 'title'],
            'x-beam-public-read' => [
                'fields' => ['title'],
                'where' => ['all' => [['field' => 'published', 'operator' => 'equals', 'value' => true]]],
            ],
            'properties' => [
                'title' => ['type' => 'string', 'x-beam-unique' => true],
                'published' => ['type' => 'boolean'],
                'secret' => ['type' => 'string'],
                'rank' => ['type' => 'integer', 'x-sort' => ['name' => 'rank', 'default' => true, 'direction' => 'desc']],
            ],
        ];
    }

    public function test_a_marked_schema_declares_its_identity_fields_and_default_sort(): void
    {
        $definition = new CollectionDefinition($this->schema());
        $this->assertSame('https://example.test/schemas/article', $definition->stem());
        $this->assertSame('Articles', $definition->label());
        $this->assertSame('title', $definition->nameField());
        $this->assertSame(['field' => 'rank', 'direction' => 'desc'], $definition->defaultSort());
        $this->assertSame(['title'], $definition->uniqueFields());
    }

    public function test_guest_projection_does_not_expose_predicate_or_private_fields(): void
    {
        $definition = new CollectionDefinition($this->schema());
        $payload = ['title' => 'Public', 'published' => true, 'secret' => 'private'];
        $this->assertTrue($definition->guestMatches($payload));
        $this->assertSame(['title' => 'Public'], $definition->publicFields($payload));
        $this->assertFalse($definition->guestMatches(['title' => 'Draft', 'published' => false]));
    }

    public function test_an_absent_public_read_gives_a_guest_nothing(): void
    {
        $schema = $this->schema();
        unset($schema['x-beam-public-read']);
        $definition = new CollectionDefinition($schema);
        $this->assertFalse($definition->guestMatches(['published' => true]));
        $this->assertSame([], $definition->publicFields(['title' => 'Private']));
    }

    public function test_unmarked_schemas_are_not_collections(): void
    {
        $schema = $this->schema();
        unset($schema['x-beam-collection']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('x-beam-collection');
        new CollectionDefinition($schema);
    }

    public function test_unknown_public_fields_are_refused_instead_of_silently_ignored(): void
    {
        $schema = $this->schema();
        $schema['x-beam-public-read']['fields'] = ['doesNotExist'];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('doesNotExist');
        new CollectionDefinition($schema);
    }

    public function test_operator_aliases_are_not_accepted(): void
    {
        $schema = $this->schema();
        $schema['x-beam-public-read']['where']['all'][0]['operator'] = 'not-equals';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('operator');
        new CollectionDefinition($schema);
    }

    public function test_two_default_sorts_are_refused(): void
    {
        $schema = $this->schema();
        $schema['properties']['title']['x-sort'] = ['name' => 'title', 'default' => true, 'direction' => 'asc'];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('default sort');
        new CollectionDefinition($schema);
    }

    public function test_nested_public_fields_preserve_shape_without_exposing_siblings(): void
    {
        $schema = $this->schema();
        $schema['properties']['author'] = ['type' => 'object', 'properties' => [
            'name' => ['type' => 'string'], 'email' => ['type' => 'string'],
        ]];
        $schema['x-beam-public-read']['fields'] = ['author.name'];
        $definition = new CollectionDefinition($schema);
        $this->assertSame(['author' => ['name' => 'Ada']], $definition->publicFields([
            'author' => ['name' => 'Ada', 'email' => 'private@example.test'],
        ]));
    }

    public function test_relations_use_unversioned_stems_and_schema_cardinality(): void
    {
        $schema = $this->schema();
        $schema['properties']['related'] = ['type' => 'array', 'items' => ['type' => 'string'],
            'x-beam-relation' => ['collection' => 'https://example.test/schemas/article']];
        $definition = new CollectionDefinition($schema);
        $this->assertSame(['collection' => 'https://example.test/schemas/article', 'many' => true], $definition->relations()['related']);
        $schema['properties']['related']['x-beam-relation']['collection'] .= '/2';
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unversioned');
        new CollectionDefinition($schema);
    }

    public function test_empty_public_fields_do_not_expose_payload(): void
    {
        $schema = $this->schema();
        $schema['x-beam-public-read'] = ['fields' => []];
        $definition = new CollectionDefinition($schema);
        $this->assertTrue($definition->guestMatches(['secret' => 'hidden']));
        $this->assertSame([], $definition->publicFields(['secret' => 'hidden']));
    }

    public function test_unknown_keyword_members_and_unary_value_arguments_are_refused(): void
    {
        $schema = $this->schema();
        $schema['x-beam-public-read']['where']['all'][0] = ['field' => 'title', 'operator' => 'is set', 'value' => 'invalid'];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('value argument');
        new CollectionDefinition($schema);
    }

    public function test_unknown_collection_options_are_not_silently_accepted(): void
    {
        $schema = $this->schema();
        $schema['x-beam-collection']['public'] = true;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Malformed x-beam-collection');
        new CollectionDefinition($schema);
    }

    public function test_date_comparisons_follow_the_declared_date_format(): void
    {
        $schema = $this->schema();
        $schema['properties']['publishedAt'] = ['type' => 'string', 'format' => 'date'];
        $schema['x-beam-public-read']['where']['all'] = [['field' => 'publishedAt', 'operator' => 'less than', 'value' => '2026-10-16']];
        $definition = new CollectionDefinition($schema);
        $this->assertTrue($definition->guestMatches(['publishedAt' => '2026-10-15']));
        $this->assertFalse($definition->guestMatches(['publishedAt' => '2026-10-17']));
        $this->assertFalse($definition->guestMatches(['publishedAt' => 'not-a-date']));
    }
}
