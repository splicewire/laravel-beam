<?php

namespace Splicewire\Beam\Particle\Backing;

use InvalidArgumentException;
use Rushing\DataFilters\Keywords;

/**
 * One facet of a {@see FilterVocabulary}: the `filter[<name>]` key, the operator that interprets it,
 * the control the panel renders, where its options come from, and whether `sort=<name>` is honoured.
 *
 * ## The same words data-filters uses, because the panel is one client
 *
 * `operator` and `control` are the strings `rushing/laravel-data-filters`' operators emit through
 * `Operator::keyword()` — `set`/`multiselect`, `search`/`search`, `exact`/`select`-or-`text`,
 * `partial`/`text` — and `@schemastud/facets` `FilterControl` is the closed list of controls the panel
 * knows. A host operator's name is legal here too (the panel keys its rendering on `control`, not on
 * `operator`), which is why both are plain strings rather than an enum this package would have to be
 * taught every host operator for.
 *
 * ⚠️ Declaring a facet does not APPLY it. The backing's `records()` still interprets the bag, and a
 * declared facet it ignores is the declaring class's lie to catch (tower pins
 * `ReviewQueueUnionSource`'s vocabulary against `ReviewInbox::applyFacets()` for exactly this).
 *
 * A facet with a null `operator` is sort-only: it carries `x-sort` and no `x-filter`, the shape a
 * `#[Sortable]` property without `#[Filterable]` reflects to.
 */
final class DeclaredFacet
{
    /**
     * @param  string  $name  the `filter[...]` / `sort` key — camelCase on the wire, as data-filters'
     *                        `FacetName` spells it
     * @param  string|null  $operator  the data-filters operator name; null ⇒ sort-only
     * @param  string|null  $control  a `@schemastud/facets` `FilterControl`; null iff sort-only
     * @param  string|null  $options  an Options Source handle, surfaced as `optionsRef`
     * @param  list<array{value: scalar, label: string}>|null  $inline  a finite domain inlined in place
     *                                                                  of an Options Source
     * @param  string|list<string>  $type  the property's JSON Schema `type`
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $operator,
        public readonly ?string $control,
        public readonly ?string $options = null,
        public readonly ?array $inline = null,
        public readonly bool $sortable = false,
        public readonly string|array $type = 'string',
        public readonly ?string $title = null,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('A declared facet needs a name; it is the `filter[...]` key.');
        }

        if ($operator === null && ! $sortable) {
            throw new InvalidArgumentException("Facet [{$name}] declares no operator and is not sortable, so it would emit nothing.");
        }

        if ($operator !== null && $control === null) {
            throw new InvalidArgumentException("Facet [{$name}] declares operator [{$operator}] with no control for the panel to render.");
        }

        if ($options !== null && $inline !== null) {
            throw new InvalidArgumentException("Facet [{$name}] names an Options Source AND inlines a domain; a control has one or the other.");
        }
    }

    /**
     * One-of membership over a value list: `filter[<name>]=a,b` → a multiselect. Relational domains
     * name an Options Source; finite ones inline it.
     *
     * @param  list<array{value: scalar, label: string}>|null  $inline
     */
    public static function set(string $name, ?string $options = null, ?array $inline = null): self
    {
        return new self($name, 'set', 'multiselect', $options, $inline, type: ['array', 'string']);
    }

    /** A single free-text value the backing searches on — the panel's leading search input. */
    public static function search(string $name): self
    {
        return new self($name, 'search', 'search');
    }

    /**
     * Exact equality. A select when the domain is known (an Options Source or an inline list), a text
     * input otherwise — the split data-filters' `Exact` operator makes off the property type.
     *
     * @param  list<array{value: scalar, label: string}>|null  $inline
     */
    public static function exact(string $name, ?string $options = null, ?array $inline = null): self
    {
        $control = $options !== null || $inline !== null ? 'select' : 'text';

        return new self($name, 'exact', $control, $options, $inline);
    }

    /** Substring match, a text input. */
    public static function partial(string $name): self
    {
        return new self($name, 'partial', 'text');
    }

    /** A sort key with no filter — `x-sort` only. */
    public static function sort(string $name): self
    {
        return new self($name, null, null, sortable: true);
    }

    public function sortable(bool $sortable = true): self
    {
        return new self($this->name, $this->operator, $this->control, $this->options, $this->inline, $sortable, $this->type, $this->title);
    }

    public function titled(string $title): self
    {
        return new self($this->name, $this->operator, $this->control, $this->options, $this->inline, $this->sortable, $this->type, $title);
    }

    /**
     * The JSON Schema property: `type`, an optional `title`, then the `x-filter` keyword shaped as
     * `Operator::keyword()` shapes it (`{ operator, name, ...control }`) and `x-sort` as
     * `FilterableAttributesStrategy` shapes it (`{ name }`).
     *
     * @return array<string, mixed>
     */
    public function toProperty(): array
    {
        $property = ['type' => $this->type];

        if ($this->title !== null) {
            $property['title'] = $this->title;
        }

        if ($this->operator !== null) {
            $property[Keywords::Filter] = [
                'operator' => $this->operator,
                'name' => $this->name,
                'control' => $this->control,
                ...$this->optionsControl(),
            ];
        }

        if ($this->sortable) {
            $property[Keywords::Sort] = ['name' => $this->name];
        }

        return $property;
    }

    /**
     * The options portion of the control, spelled exactly as `Operator::optionsControl()` spells it:
     * an `optionsRef` with its value/label keys for a relational domain, an inline `options` list for a
     * finite one, nothing for a free input.
     *
     * @return array<string, mixed>
     */
    private function optionsControl(): array
    {
        if ($this->options !== null) {
            return [
                'optionsRef' => $this->options,
                'valueKey' => 'value',
                'labelKey' => 'label',
                'searchable' => true,
            ];
        }

        if ($this->inline !== null) {
            return ['options' => $this->inline];
        }

        return [];
    }
}
