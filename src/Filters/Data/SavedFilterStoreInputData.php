<?php

namespace Splicewire\Beam\Filters\Data;

use Illuminate\Validation\Rule;
use Rushing\DataFilters\SavedFilters\Visibility;
use Schemastud\DataSchemas\Attributes\Description;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Splicewire\Beam\Data\Data;

/**
 * The `POST /{resource}/filters` body.
 *
 * Rehomed from `Splicewire\Tower\Data\SavedFilterStoreInputData` (api-surface-coherence 35) with
 * ONE field removed: `resource`. It used to be a required body field carrying a hand-maintained
 * `Rule::in(array_keys(DataFilter::registry()->all()))` membership check. The resource is now a path
 * segment, so the route says it and the registry lookup on that segment does the membership check by
 * construction — ticket 10 §3's *"the path segment replaces it"*. A body field the server would have
 * to reconcile against the path is a second source of truth for one fact.
 *
 * `query_parameters` and `is_default` keep the WIRE's snake spelling as the PROPERTY name with
 * `#[MapInputName]` retained to defeat a host's global CamelCaseMapper — ticket 54: the emitted schema
 * uses the property name and never the input-mapped one, so camel properties would publish
 * `queryParameters`/`isDefault` to a generated client while the server went on validating the snake
 * keys. Note the asymmetry this preserves and does not invent: the ENVELOPE keys are snake, while the
 * facet names INSIDE `query_parameters` are camelCase (ticket 22 / `FacetName::for()`).
 *
 * The optional fields are `X|Optional|null` rather than plain nullables (ticket 31: a plain nullable
 * still lands in the schema's `required` list, and only the `Optional` union escapes).
 */
class SavedFilterStoreInputData extends Data
{
    /**
     * @param  array<string, mixed>|null  $query_parameters
     */
    public function __construct(
        #[Description('Human-readable label for the saved filter.')]
        public string $name,

        #[MapInputName('query_parameters')]
        #[Description("The stored query-parameter blob, validated against the resource's own Filter Data class (ADR-0007). Applying a saved filter is the client replaying these parameters against the resource's index; there is no server-side apply. Facet names inside it are camelCase.")]
        public array|Optional|null $query_parameters = null,

        #[Description('Who can see the filter. Defaults to private (owner only).')]
        public string|Optional|null $visibility = null,

        #[MapInputName('is_default')]
        #[Description("Whether this filter is the owner's default view of this resource. At most one saved filter per (owner, resource) carries it — setting it here clears the flag on the owner's other filters for the same resource.")]
        public bool|Optional|null $is_default = null,
    ) {}

    /**
     * Rule keys are the INPUT-MAPPED (wire) names.
     *
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
