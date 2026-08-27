<?php

namespace Splicewire\Beam\Tests\Data;

use Schemastud\DataSchemas\Contracts\ProvidesJsonSchema;
use Splicewire\Beam\Data\Data;
use Splicewire\Beam\Tests\TestCase;

/**
 * Beam's base DTO carries the schema seam, so all 85 estate-wide subclasses get it at once.
 *
 * `Splicewire\Beam\Data\Data` was already the family's shared DTO parent ("lives in beam-base so
 * every downstream package can extend it via a legal DOWN edge"); this pins that it now also answers
 * `::jsonSchema()` through the host's CONFIGURED generator rather than a hand-built one.
 */
class BeamDataProvidesSchemaTest extends TestCase
{
    public function test_the_beam_base_dto_provides_a_schema(): void
    {
        $this->assertTrue(is_subclass_of(Data::class, ProvidesJsonSchema::class));
    }

    public function test_a_subclass_answers_with_its_own_shape(): void
    {
        $schema = SampleBeamDto::jsonSchema();

        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertArrayHasKey('label', $schema['properties'] ?? []);
    }

    public function test_the_response_helpers_still_work(): void
    {
        $this->assertSame(['label' => 'x'], (new SampleBeamDto('x'))->toResponseArray());
    }
}

class SampleBeamDto extends Data
{
    public function __construct(public string $label) {}
}
