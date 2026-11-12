<?php

namespace Splicewire\Beam\Tests\Intake;

use ReflectionClass;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Intake\Data\EntityIntakeFormSectionData;
use Splicewire\Beam\Intake\Data\EntityIntakeSectionSubmittedData;
use Splicewire\Beam\Tests\TestCase;

class EntityIntakeProtocolTest extends TestCase
{
    public function test_the_public_section_protocol_has_no_tower_dependency(): void
    {
        $this->assertFalse(class_exists('Splicewire\\Tower\\Data\\EntityIntakeFormSectionData'));
        $section = new EntityIntakeFormSectionData('identity', ['type' => 'object'], ['name' => 'Ada']);

        $this->assertSame([
            'key' => 'identity',
            'schema' => ['type' => 'object'],
            'fields' => ['name' => 'Ada'],
        ], $section->toArray());
        $this->assertSame('EntityIntakeFormSectionData', $section::jsonSchema()['$id']);
    }

    public function test_the_submitted_result_emits_without_a_tower_route(): void
    {
        $this->assertNotEmpty((new ReflectionClass(EntityIntakeSectionSubmittedData::class))->getAttributes(TypeScript::class));
        $this->assertSame(['submissionId' => 'submission-1'], (new EntityIntakeSectionSubmittedData('submission-1'))->toArray());
        $this->assertSame(['submissionId'], EntityIntakeSectionSubmittedData::jsonSchema()['required']);
    }
}
