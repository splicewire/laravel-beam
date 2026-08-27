<?php

namespace Splicewire\Beam\Tests\Fixtures;

use ReflectionClass;
use Schemastud\DataSchemas\Generators\Generator;

/**
 * The `~/Herd/thingsontv` shape, as a fixture: a NARROW generator sitting FIRST in
 * `data-schemas.generators`, ahead of the ordinary `JsonSchemaGenerator`.
 *
 * That host configures `[BlockJsonSchemaGenerator, JsonSchemaGenerator]`, and the dispatch rule
 * `schemas:generate` has always used is "the first generator whose `canGenerate()` accepts this
 * class". Any consumer that instead hardcodes `JsonSchemaGenerator` therefore compares disk
 * against a document the real command never writes.
 *
 * `$accepts` is a substring test rather than a base-class test so the fixture needs no Block
 * hierarchy: the point under test is the DISPATCH, not what a Block is.
 */
class NarrowFixtureGenerator implements Generator
{
    /** The marker schema this generator emits — deliberately unlike anything JsonSchemaGenerator produces. */
    public const SCHEMA = [
        'type' => 'object',
        'properties' => ['narrowMarker' => ['type' => 'string']],
    ];

    public function __construct(protected array $config = [], protected string $accepts = 'WidgetGateData') {}

    public function canGenerate(ReflectionClass $class): bool
    {
        return $this->accepts !== '' && str_contains($class->getName(), $this->accepts);
    }

    public function generate(ReflectionClass $class): array
    {
        return self::SCHEMA;
    }

    public function forRequest(): static
    {
        return $this;
    }

    public function forResponse(): static
    {
        return $this;
    }

    public function forLlmStrict(): static
    {
        return $this;
    }

    public function schemaMode(string $mode): static
    {
        return $this;
    }
}
