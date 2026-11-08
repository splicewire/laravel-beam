<?php

namespace Splicewire\Beam\Tests\Schema;

use InvalidArgumentException;
use Splicewire\Beam\Schema\DatabaseSchemaRegistry;
use Splicewire\Beam\Tests\TestCase;

class NestedRenameRegistrationTest extends TestCase
{
    public function test_database_schema_registration_refuses_nested_x_migrate_from(): void
    {
        $registry = new DatabaseSchemaRegistry(table: 'unused_schemas');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('x-migrate-from keyword is top-level only');
        $registry->register([
            '$id' => 'https://schemas.test/ticket31/nested/1',
            'type' => 'object',
            'properties' => [
                'profile' => ['type' => 'object', 'properties' => [
                    'displayName' => ['type' => 'string', 'x-migrate-from' => 'full_name'],
                ]],
            ],
        ]);
    }

    public function test_database_schema_registration_refuses_array_item_x_migrate_from(): void
    {
        $registry = new DatabaseSchemaRegistry(table: 'unused_schemas');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('x-migrate-from keyword is top-level only');
        $registry->register([
            '$id' => 'https://schemas.test/ticket31/items/1',
            'type' => 'object',
            'properties' => [
                'people' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'displayName' => ['type' => 'string', 'x-migrate-from' => 'full_name'],
                ]]],
            ],
        ]);
    }
}
