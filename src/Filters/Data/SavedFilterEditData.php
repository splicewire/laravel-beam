<?php

namespace Splicewire\Beam\Filters\Data;

use Illuminate\Validation\Rule;
use Rushing\DataFilters\SavedFilters\Visibility;
use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\MapValues;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Write\Contracts\MapsToModelAttributes;

/** The saved-filter edit shape; the persisted target cannot change. */
class SavedFilterEditData extends BeamData implements MapsToModelAttributes
{
    /** @param array<string, mixed> $queryParameters */
    public function __construct(
        #[Description('Human-readable name of this saved view.')]
        public string $name,
        #[Description('The immutable target resource key. It may be omitted when editing.')]
        public string|Optional $resource = new Optional,
        #[Description('Query parameters validated and cast against the target resource filter vocabulary.')]
        #[MapName('query_parameters'), MapValues]
        public array|Optional $queryParameters = new Optional,
        #[Description('Who can see the view: private, shared or public. Defaults to private on create.')]
        public string|Optional $visibility = new Optional,
        #[Description('Make this the owner default for this resource, demoting their other defaults on the same target.')]
        #[MapName('is_default')]
        public bool|Optional $isDefault = new Optional,
    ) {}

    /** The service stamps the authorized target and owner; absent fields never enter the write map. */
    public function toModelAttributes(): array
    {
        $attributes = ['name' => $this->name];
        if (is_array($this->queryParameters)) {
            $attributes['query_parameters'] = $this->queryParameters;
        }
        if (is_string($this->visibility)) {
            $attributes['visibility'] = $this->visibility;
        }
        if (is_bool($this->isDefault)) {
            $attributes['is_default'] = $this->isDefault;
        }

        return $attributes;
    }

    public static function rules(): array
    {
        return ['resource' => ['sometimes', 'string'], 'name' => ['required', 'string', 'max:255'],
            'query_parameters' => ['sometimes', 'array'], 'visibility' => ['sometimes', Rule::enum(Visibility::class)],
            'is_default' => ['sometimes', 'boolean']];
    }
}
