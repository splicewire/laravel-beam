<?php

namespace Splicewire\Beam\Doctor;

use ReflectionClass;
use Schemastud\Doctor\Finding;
use Spatie\LaravelData\Data;

/**
 * Advisory, presence-conditional: if schemastud/laravel-data-schemas is installed, run a
 * single Data -> JSON-Schema round-trip to prove the generator resolves in this app; if it
 * is not installed, PASS with a skip note (base beam is schema-agnostic — ADR-0082).
 *
 * Never a hard FAIL: a broken round-trip degrades to WARN. The generator is discovered by
 * class_exists — beam never requires data-schemas.
 */
class SchemaRoundTripAudit
{
    public function run(): Finding
    {
        $check = 'schema round-trip';
        $generatorClass = 'Schemastud\\DataSchemas\\Generators\\JsonSchemaGenerator';

        if (! class_exists($generatorClass) || ! class_exists(Data::class)) {
            return Finding::pass($check, 'data-schemas not installed — round-trip check skipped (base beam is schema-agnostic).');
        }

        try {
            $sample = new class extends Data
            {
                public string $name = 'sample';

                public int $count = 1;
            };

            $generator = new $generatorClass;
            $schema = $generator->generate(new ReflectionClass($sample));

            if (! is_array($schema) || ($schema['type'] ?? null) !== 'object' || ! isset($schema['properties'])) {
                return Finding::warn($check, 'data-schemas generated a schema with no object/properties shape — round-trip is degraded.');
            }

            $keys = implode(', ', array_keys($schema['properties']));

            return Finding::pass($check, 'data-schemas installed — sample Data round-tripped to a JSON-Schema object ('.$keys.').');
        } catch (\Throwable $e) {
            return Finding::warn($check, 'data-schemas installed but the sample round-trip threw: '.$e->getMessage().'.');
        }
    }
}
