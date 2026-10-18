<?php

namespace Splicewire\Beam\Collections;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Rushing\DataFilters\Operators\Exact;
use Rushing\DataFilters\Operators\Partial;
use Rushing\DataFilters\Operators\Range;
use Rushing\DataFilters\Operators\Search;
use Rushing\DataFilters\Operators\Set;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\Backing\AddressedResourceBacking;
use Splicewire\Beam\Particle\Backing\BacksEloquent;
use Splicewire\Beam\Particle\Backing\BacksModel;
use Splicewire\Beam\Particle\Backing\DeclaredFacet;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\EnrichesProjectedRecords;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\Backing\ResourceAddress;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\Backing\SuppliesParticleWriter;
use Splicewire\Beam\Particle\Backing\WritableRecord;
use Splicewire\Beam\Particle\Backing\WritesRecords;
use Splicewire\Beam\Read\ReadContext;
use Splicewire\Beam\Write\Contracts\WriteGate;
use Splicewire\Beam\Write\ParticleWriter;

/** A request-resolved backing, never a resource registry entry per tenant schema. */
class CollectionBacking implements AddressedResourceBacking, BacksModel, DeclaresFilterVocabulary, EnrichesProjectedRecords, QueriesRecords, StreamsRecords, SuppliesParticleWriter, WritesRecords
{
    use BacksEloquent;

    public function __construct(private Request $request, private ?CollectionSchemaResolver $schemas = null, private ?StandaloneRecordQuery $records = null) {}

    private function schemas(): CollectionSchemaResolver
    {
        return $this->schemas ?? app(CollectionSchemaResolver::class);
    }

    public function definition(): CollectionDefinition
    {
        $address = ResourceAddress::resolve($this, $this->request);

        return $this->schemas()->resolve($address->collection);
    }

    public function addressData(): string
    {
        return CollectionAddressData::class;
    }

    public function modelClass(): string
    {
        return BeamParticle::class;
    }

    public function query(array $filters): Builder
    {
        $definition = $this->definition();
        $query = ($this->records ?? app(StandaloneRecordQuery::class))->query()->whereIn('schema_ref', $this->schemas()->recordRefs($definition->stem()));
        $allowedFilters = $allowedSorts = [];
        foreach ($this->queryProperties($definition) as $path => $property) {
            $column = 'payload->'.str_replace('.', '->', $path);
            if (isset($property['x-filter'])) {
                $filter = $property['x-filter'];
                $operator = match ($filter['operator'] ?? null) {
                    'exact' => new Exact, 'partial' => new Partial, 'set' => new Set,
                    'range' => new Range, 'search' => new Search([$column]),
                    default => throw new InvalidArgumentException("Unsupported Collection x-filter on {$path}"),
                };
                $allowedFilters[] = $operator->toAllowedFilter($filter['name'], $column);
            }
            if (isset($property['x-sort'])) {
                $allowedSorts[] = AllowedSort::field($property['x-sort']['name'], $column);
            }
        }
        if (ReadContext::projectsPublic($this->request)) {
            $properties = $this->queryProperties($definition);
            $filterNames = array_column(array_column($properties, 'x-filter'), 'name');
            $sortNames = array_column(array_column($properties, 'x-sort'), 'name');
            foreach (array_keys($filters) as $name) {
                if (! in_array($name, $filterNames, true)) {
                    throw ValidationException::withMessages(['filter' => 'Guest filters must name a Public Read field.']);
                }
            }
            foreach (explode(',', (string) $this->request->query('sort', '')) as $name) {
                if ($name !== '' && ! in_array(ltrim($name, '-'), $sortNames, true)) {
                    throw ValidationException::withMessages(['sort' => 'Guest sort must name a Public Read field.']);
                }
            }
        }
        // Keep the address outside filter[]. Use the declared data-filters operators, not a second filter grammar.
        $request = clone $this->request;
        $request->query->replace(['filter' => $filters, 'sort' => $this->request->query('sort')]);
        if ($request->query('sort') === null) {
            $request->query->remove('sort');
        }
        $query = QueryBuilder::for($query, $request)->allowedFilters(...$allowedFilters)->allowedSorts(...$allowedSorts)->getEloquentBuilder();
        if (empty($query->getQuery()->orders)) {
            $sort = $definition->defaultSort();
            if ($sort !== null && ! array_key_exists($sort['field'], $this->queryProperties($definition))) {
                $sort = null;
            }
            $sort === null ? $query->latest() : $query->orderBy('payload->'.str_replace('.', '->', $sort['field']), $sort['direction']);
        }

        return $query->orderBy('id');
    }

    public function resolveForWrite(string $id, array $filters): ?WritableRecord
    {
        $model = $this->query($filters)->find($id);

        return $model === null ? null : new WritableRecord($model);
    }

    public function newRecord(): WritableRecord
    {
        return new WritableRecord(new BeamParticle(['schema_ref' => $this->definition()->schema['$id']]));
    }

    public function writer(WriteGate $gate): ParticleWriter
    {
        return app()->makeWith(CollectionWriter::class, ['gate' => $gate])->pipeline();
    }

    public function enrich(array $records, ?Authenticatable $actor): array
    {
        $records = array_map(fn ($record) => $record instanceof CollectionRecordData ? $record : CollectionRecordData::from($record), $records);
        $relations = app(CollectionRelations::class)->resolve($records, $actor, ReadContext::fromRequest($this->request));
        foreach ($records as $record) {
            $record->relations = $relations[$record->id] ?? [];
        }

        return array_map(fn (CollectionRecordData $record) => $record->toArray(), $records);
    }

    /** ADR-0230 d3: hidden fields cannot become guest filter/sort oracles. */
    private function queryProperties(CollectionDefinition $definition): array
    {
        return ! ReadContext::projectsPublic($this->request)
            ? $definition->properties()
            : array_intersect_key($definition->properties(), array_flip($definition->publicFieldNames()));
    }

    public function filterVocabulary(): FilterVocabulary
    {
        $facets = [];
        foreach ($this->queryProperties($this->definition()) as $path => $property) {
            $filter = $property['x-filter'] ?? null;
            $sort = $property['x-sort'] ?? null;
            if ($filter !== null) {
                if (! is_array($filter) || ! is_string($filter['name'] ?? null) || ! is_string($filter['control'] ?? null)
                    || ! in_array($filter['operator'] ?? null, ['exact', 'partial', 'set', 'range', 'search'], true)) {
                    throw new InvalidArgumentException("Malformed Collection x-filter on {$path}");
                }
                $facets[] = new DeclaredFacet($filter['name'], $filter['operator'], $filter['control'],
                    options: $filter['optionsRef'] ?? null, inline: $filter['options'] ?? null,
                    sortable: $sort !== null && $sort['name'] === $filter['name'], type: $property['type'] ?? 'string', title: $property['title'] ?? null);
            }
            if ($sort !== null && ($filter === null || $sort['name'] !== $filter['name'])) {
                $facets[] = DeclaredFacet::sort($sort['name']);
            }
        }

        return FilterVocabulary::of(...$facets);
    }
}
