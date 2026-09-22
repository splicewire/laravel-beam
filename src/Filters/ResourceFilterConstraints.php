<?php

namespace Splicewire\Beam\Filters;

use Rushing\DataFilters\Facades\DataFilter;
use Rushing\Popcorn\Laravel\Rules\ExistsInRegistry;
use Rushing\Popcorn\Registries\Key;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;

/** The filter route's wire vocabulary, shared by validation, discovery and documentation. */
class ResourceFilterConstraints
{
    public static function options(?FilterVocabulary $vocabulary = null): ExistsInRegistry
    {
        $allowed = $vocabulary === null ? null : array_map(
            fn (string $ref): string => 'data-filters.options.'.$ref,
            $vocabulary->optionsRefs(),
        );

        return new ExistsInRegistry(
            'data-filters.options',
            relative: true,
            where: $allowed === null ? null : fn (Key $key): bool => in_array((string) $key, $allowed, true),
        );
    }

    public static function variants(?string $resource): ExistsInRegistry
    {
        return new ExistsInRegistry(
            'data-filters.resources',
            relative: true,
            where: $resource === null ? null : fn (Key $key): bool => DataFilter::registry()->find((string) $key)?->resource === $resource,
        );
    }
}
