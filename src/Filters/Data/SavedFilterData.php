<?php

namespace Splicewire\Beam\Filters\Data;

use Rushing\DataFilters\SavedFilters\SavedFilter;
use Schemastud\DataSchemas\Attributes\MapValues;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Attributes\WithTransformer;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Filters\SavedFilterResourceHandler;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/** Contextual CRUD resource; an empty label and section keep it out of primary navigation. */
#[ParticleResource(
    key: 'saved-filters',
    backing: SavedFilter::class,
    data: SavedFilterData::class,
    input: SavedFilterInputData::class,
    editData: SavedFilterEditData::class,
    filterable: false,
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
        #[MapName('owner_type')]
        public string|Optional|null $ownerType = new Optional,
        #[MapName('owner_id')]
        public string|int|Optional|null $ownerId = new Optional,
        #[MapName('context_type')]
        public string|Optional|null $contextType = new Optional,
        #[MapName('context_id')]
        public string|int|Optional|null $contextId = new Optional,
        #[MapName('created_at')]
        public string|Optional|null $createdAt = new Optional,
        #[MapName('updated_at')]
        public string|Optional|null $updatedAt = new Optional,
    ) {}

    public static function fromSavedFilter(SavedFilter $saved): self
    {
        return new self($saved->id, $saved->name, $saved->resource, $saved->query_parameters ?? [], $saved->visibility->value, $saved->is_default);
    }

    public static function withLegacyMetadata(SavedFilter $saved): self
    {
        return self::from([...$saved->toArray(), 'query_parameters' => $saved->query_parameters ?? []]);
    }
}
