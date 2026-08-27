<?php

namespace Splicewire\Beam\Doctor;

use ReflectionClass;
use Rushing\Doctor\Finding;
use Schemastud\DataSchemas\Generators\Generator;
use Spatie\LaravelData\Data;

/**
 * Advisory: run a single Data -> JSON-Schema round-trip to prove the generator THIS HOST CONFIGURED
 * resolves and produces an object shape.
 *
 * Never a hard FAIL: a broken round-trip degrades to WARN.
 *
 * THE GENERATOR IS THE HOST'S, not a default. This audit used to build a bare
 * `new JsonSchemaGenerator` with no config — the last such site in beam's `src/` after
 * beam-facade 105 — which proved a generator no host actually generates with: `generators` is a
 * LIST, and `schemas:generate` dispatches to the first entry whose `canGenerate()` accepts the
 * class. Resolving {@see Generator} from the container gets the host's whole chain instead, so an
 * unloadable or non-conforming entry in that list is something this audit can finally see.
 *
 * ⚠️ `ChainedGenerator::generate()` THROWS when nothing accepts the class, so `canGenerate()` is
 * asked first and answered as its own WARN — an advisory audit must not take the sweep down.
 */
class SchemaRoundTripAudit
{
    public function run(): Finding
    {
        $check = 'schema round-trip';

        try {
            $sample = new class extends Data
            {
                public string $name = 'sample';

                public int $count = 1;
            };

            $reflection = new ReflectionClass($sample);
            $generator = app(Generator::class);

            if (! $generator->canGenerate($reflection)) {
                return Finding::warn($check, 'no configured generator accepts a plain spatie/laravel-data class — check `data-schemas.generators`.');
            }

            $schema = $generator->generate($reflection);

            if (($schema['type'] ?? null) !== 'object' || ! isset($schema['properties'])) {
                return Finding::warn($check, 'data-schemas generated a schema with no object/properties shape — round-trip is degraded.');
            }

            $keys = implode(', ', array_keys($schema['properties']));

            return Finding::pass($check, 'data-schemas installed — sample Data round-tripped to a JSON-Schema object ('.$keys.').');
        } catch (\Throwable $e) {
            return Finding::warn($check, 'data-schemas installed but the sample round-trip threw: '.$e->getMessage().'.');
        }
    }
}
