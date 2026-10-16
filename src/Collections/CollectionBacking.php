<?php

namespace Splicewire\Beam\Collections;

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
use Splicewire\Beam\Particle\Backing\BacksEloquent;
use Splicewire\Beam\Particle\Backing\BacksModel;
use Splicewire\Beam\Particle\Backing\DeclaredFacet;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\Backing\SuppliesParticleWriter;
use Splicewire\Beam\Particle\Backing\WritableRecord;
use Splicewire\Beam\Particle\Backing\WritesRecords;
use Splicewire\Beam\Write\Contracts\WriteGate;
use Splicewire\Beam\Write\ParticleWriter;

/** A request-resolved backing, never a resource registry entry per tenant schema. */
class CollectionBacking implements BacksModel, DeclaresFilterVocabulary, QueriesRecords, StreamsRecords, SuppliesParticleWriter, WritesRecords
{
    use BacksEloquent;

    public function __construct(private Request $request, private CollectionSchemaResolver $schemas, private StandaloneRecordQuery $records) {}

    public function definition(): CollectionDefinition
    {
        $stem = $this->request->query('collection');
        if (! is_string($stem) || $stem === '') {
            throw ValidationException::withMessages(['collection' => 'The collection query parameter is required.']);
        }

        return $this->schemas->resolve($stem);
    }

    public function modelClass(): string
    {
        return BeamParticle::class;
    }

    public function query(array $filters): Builder
    {
        $definition = $this->definition();
        $query = $this->records->query()->whereIn('schema_ref', $this->schemas->recordRefs($definition->stem()));
        $allowedFilters = $allowedSorts = [];
        foreach ($definition->properties() as $path => $property) {
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
        // Keep the address outside filter[]. Use the declared data-filters operators, not a second filter grammar.
        $request = clone $this->request;
        $request->query->replace(['filter' => $filters, 'sort' => $this->request->query('sort')]);
        if ($request->query('sort') === null) {
            $request->query->remove('sort');
        }
        $query = QueryBuilder::for($query, $request)->allowedFilters(...$allowedFilters)->allowedSorts(...$allowedSorts)->getEloquentBuilder();
        if (empty($query->getQuery()->orders)) {
            $sort = $definition->defaultSort();
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

    public function filterVocabulary(): FilterVocabulary
    {
        $facets = [];
        foreach ($this->definition()->properties() as $path => $property) {
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
