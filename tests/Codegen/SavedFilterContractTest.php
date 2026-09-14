<?php

namespace Splicewire\Beam\Tests\Codegen;

use ReflectionClass;
use Schemastud\DataSchemas\Generators\Generator;
use Splicewire\Beam\Codegen\DeclaredParticleTypes;
use Splicewire\Beam\Filters\Data\SavedFilterData;
use Splicewire\Beam\Filters\Data\SavedFilterEditData;
use Splicewire\Beam\Filters\Data\SavedFilterInputData;
use Splicewire\Beam\Filters\Data\SavedFilterListResponseData;
use Splicewire\Beam\Tests\TestCase;

class SavedFilterContractTest extends TestCase
{
    public function test_saved_filter_contracts_generate_objects_and_typed_list_members(): void
    {
        $generator = app(Generator::class)->forResponse();
        $read = $generator->generate(new ReflectionClass(SavedFilterData::class));
        $input = $generator->forRequest()->generate(new ReflectionClass(SavedFilterInputData::class));
        $edit = $generator->forRequest()->generate(new ReflectionClass(SavedFilterEditData::class));
        $this->assertContains('resource', $input['required']);
        $this->assertNotContains('resource', $edit['required']);
        $this->assertArrayHasKey(SavedFilterEditData::class, app(DeclaredParticleTypes::class)->declared());
        $list = $generator->forResponse()->generate(new ReflectionClass(SavedFilterListResponseData::class));
        $this->assertSame('object', $read['properties']['query_parameters']['type']);
        $this->assertSame('object', $input['properties']['query_parameters']['type']);
        $this->assertSame('string', $read['properties']['id']['type']);
        $this->assertSame('boolean', $read['properties']['is_default']['type']);
        $this->assertSame('array', $list['properties']['data']['type']);
        $this->assertSame('#/$defs/SavedFilterData', $list['properties']['data']['items']['$ref']);
        $this->assertArrayHasKey(SavedFilterData::class, app(DeclaredParticleTypes::class)->declared());
        $this->assertArrayHasKey(SavedFilterInputData::class, app(DeclaredParticleTypes::class)->declared());
        $empty = new SavedFilterData('id', 'Empty', 'papers', [], 'private', false);
        $this->assertInstanceOf(\stdClass::class, json_decode($empty->toJson())->query_parameters);
        $this->assertSame([], $empty->queryParameters);
    }
}
