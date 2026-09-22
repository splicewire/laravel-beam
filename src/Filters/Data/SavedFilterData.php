<?php

namespace Splicewire\Beam\Filters\Data;

use Rushing\DataFilters\SavedFilters\SavedFilter;
use Schemastud\DataSchemas\Attributes\MapValues;
use Schemastud\Frame\Authorization\ResourceAuthorizer;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\Data\ResourceCapabilitiesData;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\WithTransformer;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Filters\SavedFilterResourceHandler;
use Splicewire\Beam\Filters\SavedFilterService;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/** Contextual CRUD resource; an empty label and section keep it out of primary navigation. */
#[ParticleResource(
    key: 'saved-filters',
    backing: SavedFilter::class,
    data: SavedFilterData::class,
    input: SavedFilterInputData::class,
    editData: SavedFilterEditData::class,
    frame: true,
    handler: SavedFilterResourceHandler::class,
)]
class SavedFilterData extends BeamData
{
    /** @param array<string, mixed> $queryParameters */
    public function __construct(
        public string $id,
        public string $name,
        public string $resource,
        #[MapName('query_parameters'), MapValues]
        #[WithTransformer(QueryParametersTransformer::class)]
        public array $queryParameters,
        public string $visibility,
        #[MapName('is_default')]
        public bool $isDefault,
        public ResourceCapabilitiesData $can,
    ) {}

    public static function fromSavedFilter(SavedFilter $saved): self
    {
        return new self($saved->id, $saved->name, $saved->resource, $saved->query_parameters ?? [], $saved->visibility->value, $saved->is_default, self::capabilities($saved));
    }

    private static function capabilities(SavedFilter $saved): ResourceCapabilitiesData
    {
        $definition = app(ResourceRegistry::class)->get('saved-filters');

        $capabilities = app(ResourceAuthorizer::class)->recordCapabilities($definition, $saved);
        if (! app(SavedFilterService::class)->allowsMutation($saved)) {
            $capabilities['update'] = false;
            $capabilities['delete'] = false;
        }

        return ResourceCapabilitiesData::from($capabilities);
    }
}
