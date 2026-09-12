<?php

namespace Splicewire\Beam\Particle\Backing;

use InvalidArgumentException;

/**
 * What a {@see DeclaresFilterVocabulary} backing advertises: an ordered set of {@see DeclaredFacet}s,
 * unique by name, projected on demand to the filter schema the sub-surface serves.
 *
 * A plain immutable value rather than a Data class on purpose: it is not itself a wire shape. What
 * crosses the wire is {@see toSchema()}'s JSON Schema, the SAME document data-filters generates off a
 * `#[Filterable]` Data class, so `@schemastud/facets` reads a declared vocabulary and a reflected one
 * through one `FilterSchema` type.
 */
final class FilterVocabulary
{
    /** @var array<string, DeclaredFacet> keyed by facet name, declaration order kept */
    private array $facets = [];

    private function __construct(DeclaredFacet ...$facets)
    {
        foreach ($facets as $facet) {
            if (isset($this->facets[$facet->name])) {
                throw new InvalidArgumentException(
                    "Filter vocabulary declares facet [{$facet->name}] twice; a facet name is the `filter[...]` key and must be unique."
                );
            }

            $this->facets[$facet->name] = $facet;
        }
    }

    public static function of(DeclaredFacet ...$facets): self
    {
        return new self(...$facets);
    }

    public function isEmpty(): bool
    {
        return $this->facets === [];
    }

    /** @return list<DeclaredFacet> */
    public function facets(): array
    {
        return array_values($this->facets);
    }

    /** @return list<string> the `filter[...]` / `sort` keys, in declaration order */
    public function names(): array
    {
        return array_keys($this->facets);
    }

    /**
     * Every Options Source handle a facet references, deduplicated, in declaration order.
     *
     * @return list<string>
     */
    public function optionsRefs(): array
    {
        $refs = [];

        foreach ($this->facets as $facet) {
            if ($facet->options !== null) {
                $refs[$facet->options] = true;
            }
        }

        return array_keys($refs);
    }

    /**
     * Does this vocabulary reach the named Options Source? The options registry is a flat,
     * cross-resource namespace; a resource enumerates only the handles its own facets name.
     */
    public function references(string $optionsRef): bool
    {
        return in_array($optionsRef, $this->optionsRefs(), true);
    }

    /**
     * The JSON Schema the filter sub-surface serves — `{"type":"object","properties":{…}}`, one
     * property per facet carrying its `x-filter` / `x-sort` keywords.
     *
     * `properties` is `(object) []` when empty, never `[]`: an empty PHP array encodes as a JSON ARRAY
     * and the wire contract's `properties` is an object (`FilterSchema` in `@schemastud/facets`).
     *
     * @return array{type: 'object', properties: array<string, array<string, mixed>>|object}
     */
    public function toSchema(): array
    {
        $properties = [];

        foreach ($this->facets as $name => $facet) {
            $properties[$name] = $facet->toProperty();
        }

        return [
            'type' => 'object',
            'properties' => $properties === [] ? (object) [] : $properties,
        ];
    }
}
