<?php

namespace Splicewire\Beam\Schema;

use BackedEnum;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\DataSchemas\Contracts\ProvidesEnumLabel;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The classes whose rendered label would be an identifier (app-walkthrough APP-09, APP-21). Scope: every class
 * reachable from a booted particle operation's `input:`/`output:` and a resource's `data:`/`input:`, following Data
 * properties (declared types and `#[DataCollectionOf]`). A unit is:
 *
 * - `object`: a Data class with no #[Title]. Under `identifier_titles => false` it carries no title; otherwise its
 *   class short name is the title.
 * - `enum`: a backed enum that does not implement ProvidesEnumLabel, so its options render backing values.
 *
 * It reads the BOOTED registries, so it measures what this host mounts.
 */
final class IdentifierLabels
{
    public function __construct(
        private ParticleOperationRegistry $operations,
        private ParticleResourceRegistry $resources,
    ) {}

    /** @return array<class-string, 'object'|'enum'> sorted by class */
    public function find(): array
    {
        $found = [];

        foreach ($this->reachable() as $class => $kind) {
            if ($kind === 'enum') {
                if (is_subclass_of($class, BackedEnum::class) && ! is_subclass_of($class, ProvidesEnumLabel::class)) {
                    $found[$class] = 'enum';
                }

                continue;
            }

            if ((new ReflectionClass($class))->getAttributes(Title::class) === []) {
                $found[$class] = 'object';
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Every Data class and enum reachable from this host's particles, by the same walk {@see find()} reads (also the
     * localization 12 key catalog's population).
     *
     * @return array<class-string, 'data'|'enum'> sorted by class
     */
    public function reachable(): array
    {
        $reachable = [];
        $seen = [];
        $queue = $this->roots();

        while ($queue !== []) {
            $class = array_shift($queue);
            if (isset($seen[$class])) {
                continue;
            }
            $seen[$class] = true;

            if (enum_exists($class)) {
                $reachable[$class] = 'enum';

                continue;
            }

            if (! class_exists($class) || ! is_subclass_of($class, Data::class)) {
                continue;
            }

            $reachable[$class] = 'data';
            array_push($queue, ...$this->referenced(new ReflectionClass($class)));
        }

        ksort($reachable);

        return $reachable;
    }

    /** @return list<string> */
    private function roots(): array
    {
        $roots = [];

        foreach ($this->operations->unfiltered()->matches('beam.particle.operations') as $op) {
            /** @var ParticleOperation $op */
            if (is_string($op->input)) {
                $roots[] = $op->input;
            }
            if (is_string($op->output)) {
                $roots[] = $op->output;
            } elseif (is_array($op->output)) {
                foreach ($op->output as $payloads) {
                    array_push($roots, ...array_values((array) $payloads));
                }
            }
        }

        foreach ($this->resources->unfiltered()->matches('beam.particle.resources') as $resource) {
            /** @var ParticleResource $resource */
            foreach ([$resource->data, $resource->input] as $class) {
                if (is_string($class)) {
                    $roots[] = $class;
                }
            }
        }

        return array_values(array_unique($roots));
    }

    /** @return list<string> the classes a Data class's public properties name */
    private function referenced(ReflectionClass $class): array
    {
        $names = [];

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $type = $property->getType();
            $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
            foreach ($types as $part) {
                if ($part instanceof ReflectionNamedType && ! $part->isBuiltin()) {
                    $names[] = $part->getName();
                }
            }

            foreach ($property->getAttributes(DataCollectionOf::class) as $attribute) {
                $item = $attribute->getArguments()[0] ?? null;
                if (is_string($item)) {
                    $names[] = $item;
                }
            }
        }

        return $names;
    }
}
