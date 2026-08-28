<?php

namespace Splicewire\Beam\Tests\Data;

use PHPUnit\Framework\Attributes\DataProvider;
use Schemastud\DataSchemas\Contracts\ProvidesJsonSchema;
use Splicewire\Beam\Data\BeamSchemaData;
use Splicewire\Beam\Data\BeamSchemaInputData;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\GitRepoData;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Data\HookInputData;
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
        $this->assertTrue(is_subclass_of(BeamData::class, ProvidesJsonSchema::class));
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

    /**
     * Beam's OWN particle-declared DTOs sit on that base too.
     *
     * They did not. Beam shipped the base class and then declared all five of its internal particle
     * slots — the `hooks`, `schemas` and `git-repo` `data:`/`input:` classes — on Spatie's `Data`
     * directly, so the one package that authors the doctrine held the only particle DTOs in the estate
     * that could not answer `::jsonSchema()`. Asserted by SLOT rather than by file, so a sixth
     * declaration added later is covered by construction.
     *
     * @return list<array{class-string}>
     */
    public static function beamsOwnParticleSlots(): array
    {
        return [
            [HookData::class],
            [HookInputData::class],
            [BeamSchemaData::class],
            [BeamSchemaInputData::class],
            [GitRepoData::class],
        ];
    }

    #[DataProvider('beamsOwnParticleSlots')]
    public function test_beams_own_particle_slot_dtos_answer_with_a_schema(string $class): void
    {
        $this->assertTrue(is_subclass_of($class, BeamData::class), $class.' is not on beam\'s own Data base.');
        $this->assertSame('object', $class::jsonSchema()['type'] ?? null);
    }
}

class SampleBeamDto extends BeamData
{
    public function __construct(public string $label) {}
}
