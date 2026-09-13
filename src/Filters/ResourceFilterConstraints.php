<?php

namespace Splicewire\Beam\Filters;

use Rushing\DataFilters\Facades\DataFilter;
use Rushing\Popcorn\Laravel\Rules\ExistsInRegistry;
use Rushing\Popcorn\Registries\Key;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/** The filter route's wire vocabulary, shared by validation, discovery and documentation. */
class ResourceFilterConstraints
{
    public static function resources(): ExistsInRegistry
    {
        return new ExistsInRegistry('data-filters.resources', relative: true);
    }

    /** @return list<string> Resource routes also accept declarations without a data-filters entry. */
    public static function resourceValues(string $action): array
    {
        $keys = self::resources()->values();

        if (in_array($action, ['schema', 'options'], true)) {
            foreach (app(ParticleResourceRegistry::class)->all() as $resource) {
                if ((new BackingResolver)->hasCapability($resource->backing, DeclaresFilterVocabulary::class)
                    || ($action === 'schema' && ! $resource->filterable)) {
                    $keys[] = $resource->key;
                }
            }
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

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
