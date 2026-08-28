<?php

namespace Splicewire\Beam\Filters\Data;

use Illuminate\Validation\Rule;
use Rushing\DataFilters\SavedFilters\Visibility;
use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Data\BeamData;

/**
 * The `PUT|PATCH /{resource}/filters/{id}` body.
 *
 * Rehomed from `Splicewire\Tower\Data\SavedFilterUpdateInputData` (api-surface-coherence 35). It
 * used to carry a `sometimes` `resource` field that was validated against the registry and then
 * **discarded** in favour of the saved filter's own resource — a field the endpoint accepted and
 * ignored. It is gone with the path segment, along with the membership rule.
 *
 * Still deliberately NOT shared with {@see SavedFilterStoreInputData}. The reason changed shape but
 * did not go away: `name` is `required` on create and `sometimes` here, so one union DTO would
 * publish it as optional on the endpoint that demands it.
 */
class SavedFilterUpdateInputData extends BeamData
{
    /**
     * @param  array<string, mixed>|null  $query_parameters
     */
    public function __construct(
        #[Description('Human-readable label for the saved filter.')]
        public string $name,

        #[MapInputName('query_parameters')]
        #[Description("The stored query-parameter blob, validated against the resource's own Filter Data class (ADR-0007). Facet names inside it are camelCase.")]
        public array|Optional|null $query_parameters = null,

        #[Description('Who can see the filter. Omitted leaves the stored visibility alone.')]
        public string|Optional|null $visibility = null,

        #[MapInputName('is_default')]
        #[Description("Whether this filter is the owner's default view of this resource. At most one saved filter per (owner, resource) carries it — setting it here clears the flag on the owner's other filters for the same resource.")]
        public bool|Optional|null $is_default = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'query_parameters' => ['sometimes', 'array'],
            'visibility' => ['sometimes', Rule::enum(Visibility::class)],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
