<?php

namespace Splicewire\Beam\Config;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\QueryException;
use ReflectionClass;
use ReflectionProperty;
use Spatie\LaravelData\Contracts\BaseData;
use Spatie\LaravelData\Support\DataClass;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\LaravelData\Support\DataProperty;

/**
 * The ONE wire-name source for a Config Section's classes (config-sections ticket 13, condition 2).
 *
 * A config key is a PHP property — named exactly as the key when the key is a PHP identifier, or a mapped identifier
 * carrying the key on the wire through a Spatie `#[MapName]` (emitted by {@see Generator\ConfigSectionEmitter} for a
 * non-identifier key). Coverage ({@see ConfigSectionCoverage}) and the schema ({@see ConfigSectionSchema}) must resolve
 * that wire name the SAME way the data-schemas generator does, or the committed schema, the coverage census and the
 * emitted class would disagree about what a key is called. So the name is ASKED OF SPATIE — never re-derived from
 * attributes, which cannot see a class-level mapper or the host's global strategy — and keyed on the OUTPUT mapped
 * name, the axis the generator keys on in its default (non-request) mode, the mode {@see ConfigSectionSchema::for()}
 * generates in. The floor, when no booted application is bound (standalone use), is the PHP name, exactly as the
 * generator degrades in {@see \Schemastud\DataSchemas\Generators\JsonSchemaGenerator::wireName()}.
 */
class ConfigWireNames
{
    /**
     * Wire name => the property it names and that property's Spatie declaration (null when Spatie cannot be asked).
     *
     * @param  class-string  $class
     * @return array<string, array{0: ReflectionProperty, 1: ?DataProperty}>
     */
    public static function map(string $class): array
    {
        $dataClass = self::dataClass($class);
        $map = [];

        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $spatie = $dataClass?->properties[$property->getName()] ?? null;
            $map[$spatie?->outputMappedName ?? $property->getName()] = [$property, $spatie];
        }

        return $map;
    }

    /**
     * Spatie's declaration model for a Data class, or null when it cannot be asked — the class is not a Data class, or
     * no booted application is bound. Mirrors
     * {@see \Schemastud\DataSchemas\Generators\JsonSchemaGenerator::spatieDataConfig()} so the two never disagree.
     *
     * @param  class-string  $class
     */
    private static function dataClass(string $class): ?DataClass
    {
        if (! is_subclass_of($class, BaseData::class)) {
            return null;
        }

        $container = Container::getInstance();

        if (! $container instanceof Application || ! $container->bound('config')) {
            return null;
        }

        try {
            return $container->make(DataConfig::class)->getDataClass($class);
        } catch (QueryException) {
            $config = $container->make('config')->get('data', []);

            return DataConfig::createFromConfig(is_array($config) ? $config : [])->getDataClass($class);
        }
    }
}
