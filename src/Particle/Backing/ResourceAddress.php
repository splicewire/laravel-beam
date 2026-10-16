<?php

namespace Splicewire\Beam\Particle\Backing;

use Illuminate\Http\Request;
use ReflectionClass;
use ReflectionProperty;
use Spatie\LaravelData\Data;

/** One declaration-driven validator shared by runtime reads and registry inspection. */
final class ResourceAddress
{
    public static function resolve(AddressedResourceBacking $backing, Request $request, bool $required = true): ?Data
    {
        $class = $backing->addressData();
        $names = array_map(fn (ReflectionProperty $property) => $property->getName(),
            array_filter((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC), fn ($property) => ! $property->isStatic()));
        $input = array_intersect_key($request->query(), array_flip($names));
        if (! $required && $input === []) {
            return null;
        }

        return $class::validateAndCreate($input);
    }
}
