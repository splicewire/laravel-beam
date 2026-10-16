<?php

namespace Splicewire\Beam\Collections;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Splicewire\Beam\Schema\SchemaId;

/** Validated ADR-0235 declaration. JSON Schema remains the source; this is not a second registry. */
final class CollectionDefinition
{
    private array $properties = [];

    private array $unique = [];

    private array $relations = [];

    private ?array $sort = null;

    private ?array $public = null;

    private array $collection;

    public function __construct(public readonly array $schema)
    {
        $id = $schema['$id'] ?? null;
        if (! is_string($id) || ! filter_var($id, FILTER_VALIDATE_URL) || SchemaId::from($id)->version() === null) {
            throw new InvalidArgumentException('A Collection needs an absolute versioned schema $id.');
        }
        $this->collection = $this->object($schema['x-beam-collection'] ?? null, ['label', 'nameField'], 'x-beam-collection');
        $this->collectProperties($schema);
        if (! is_string($this->collection['label'] ?? null) || trim($this->collection['label']) === '') {
            throw new InvalidArgumentException('x-beam-collection label must be nonempty.');
        }
        $this->field($this->collection['nameField'] ?? null);
        foreach ($this->properties as $path => $property) {
            if (array_key_exists('x-beam-unique', $property)) {
                if ($property['x-beam-unique'] !== true || ! in_array($property['type'] ?? null, ['string', 'number', 'integer', 'boolean'], true)) {
                    throw new InvalidArgumentException("x-beam-unique requires a scalar field: {$path}");
                }
                $this->unique[] = $path;
            }
            if (array_key_exists('x-beam-relation', $property)) {
                $relation = $this->object($property['x-beam-relation'], ['collection'], 'x-beam-relation');
                $stem = $relation['collection'] ?? null;
                if (! is_string($stem) || ! filter_var($stem, FILTER_VALIDATE_URL) || SchemaId::from($stem)->version() !== null) {
                    throw new InvalidArgumentException("x-beam-relation needs an absolute unversioned collection: {$path}");
                }
                $many = ($property['type'] ?? null) === 'array' && ($property['items']['type'] ?? null) === 'string';
                if (! $many && ($property['type'] ?? null) !== 'string') {
                    throw new InvalidArgumentException("x-beam-relation requires string or string[]: {$path}");
                }
                $this->relations[$path] = ['collection' => $stem, 'many' => $many];
            }
            if (array_key_exists('x-sort', $property)) {
                $sort = $this->object($property['x-sort'], ['name', 'default', 'direction'], 'x-sort');
                if (! is_string($sort['name'] ?? null) || $sort['name'] === '' || ! is_bool($sort['default'] ?? false) || ! in_array($sort['direction'] ?? 'asc', ['asc', 'desc'], true)) {
                    throw new InvalidArgumentException("Malformed x-sort on {$path}");
                }
                if ($sort['default'] ?? false) {
                    if ($this->sort !== null) {
                        throw new InvalidArgumentException('A Collection may have only one default sort.');
                    }
                    $this->sort = ['field' => $path, 'direction' => $sort['direction'] ?? 'asc'];
                }
            }
        }
        if (array_key_exists('x-beam-public-read', $schema)) {
            $this->public = $this->object($schema['x-beam-public-read'], ['fields', 'where'], 'x-beam-public-read');
            $fields = $this->public['fields'] ?? null;
            if (! is_array($fields) || ! array_is_list($fields)) {
                throw new InvalidArgumentException('x-beam-public-read fields must be a list.');
            }
            foreach ($fields as $field) {
                $this->field($field);
            }
            if (array_key_exists('where', $this->public)) {
                $where = $this->object($this->public['where'], ['all', 'any'], 'public rule');
                if (count($where) !== 1 || ! is_array(reset($where)) || ! array_is_list(reset($where)) || reset($where) === []) {
                    throw new InvalidArgumentException('A public rule needs exactly one nonempty all/any list.');
                }
                foreach (reset($where) as $rule) {
                    $rule = $this->object($rule, ['field', 'operator', 'value'], 'public rule');
                    $this->field($rule['field'] ?? null);
                    // ADR-0229 decision 12: these are the exact shared spellings, not aliases.
                    if (! in_array($rule['operator'] ?? null, ['is set', 'is empty', 'equals', 'does not equal', 'greater than', 'less than', 'contains'], true)) {
                        throw new InvalidArgumentException('Unknown public rule operator.');
                    }
                    $unary = in_array($rule['operator'], ['is set', 'is empty'], true);
                    if ($unary === array_key_exists('value', $rule)) {
                        throw new InvalidArgumentException('Public rule operator has an invalid value argument.');
                    }
                }
            }
        }
    }

    public function stem(): string
    {
        return SchemaId::from($this->schema['$id'])->stem();
    }

    public function label(): string
    {
        return $this->collection['label'];
    }

    public function nameField(): string
    {
        return $this->collection['nameField'];
    }

    public function defaultSort(): ?array
    {
        return $this->sort;
    }

    public function uniqueFields(): array
    {
        return $this->unique;
    }

    public function relations(): array
    {
        return $this->relations;
    }

    public function hasPublicRead(): bool
    {
        return $this->public !== null;
    }

    public function publicFields(array $payload): array
    {
        $result = [];
        foreach ($this->public['fields'] ?? [] as $field) {
            if (Arr::has($payload, $field)) {
                Arr::set($result, $field, Arr::get($payload, $field));
            }
        }

        return $result;
    }

    public function guestMatches(array $payload): bool
    {
        if ($this->public === null) {
            return false;
        }
        if (! isset($this->public['where'])) {
            return true;
        }
        $where = $this->public['where'];
        $results = array_map(fn (array $rule): bool => $this->matches($payload, $rule), reset($where));

        return array_key_exists('all', $where) ? ! in_array(false, $results, true) : in_array(true, $results, true);
    }

    private function matches(array $payload, array $rule): bool
    {
        $actual = Arr::get($payload, $rule['field']);
        $expected = $rule['value'] ?? null;

        return match ($rule['operator']) {
            'is set' => $actual !== null,
            'is empty' => $actual === null || $actual === '' || $actual === [],
            'equals' => $actual === $expected,
            'does not equal' => $actual !== $expected,
            'greater than' => $this->compare($rule['field'], $actual, $expected) === 1,
            'less than' => $this->compare($rule['field'], $actual, $expected) === -1,
            'contains' => is_array($actual) ? in_array($expected, $actual, true) : (is_string($actual) && is_string($expected) && str_contains($actual, $expected)),
        };
    }

    private function compare(string $field, mixed $actual, mixed $expected): ?int
    {
        $format = $this->properties[$field]['format'] ?? null;
        if (! in_array($format, ['date', 'date-time'], true)) {
            return is_numeric($actual) && is_numeric($expected) ? $actual <=> $expected : null;
        }
        $dates = [];
        foreach ([$actual, $expected] as $value) {
            if (! is_string($value)) {
                return null;
            }
            if ($format === 'date') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if ($date === false || $date->format('Y-m-d') !== $value) {
                    return null;
                }
            } else {
                if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
                    return null;
                }
                try {
                    $date = new \DateTimeImmutable($value);
                } catch (\Exception) {
                    return null;
                }
                $errors = \DateTimeImmutable::getLastErrors();
                if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) {
                    return null;
                }
            }
            $dates[] = $date;
        }

        return $dates[0] <=> $dates[1];
    }

    private function field(mixed $field): void
    {
        if (! is_string($field) || ! array_key_exists($field, $this->properties)) {
            throw new InvalidArgumentException('Unknown Collection field: '.(is_scalar($field) ? $field : '(missing)'));
        }
    }

    private function collectProperties(array $schema, string $prefix = ''): void
    {
        foreach ($schema['properties'] ?? [] as $name => $property) {
            if (! is_string($name) || $name === '' || str_contains($name, '.') || ! is_array($property)) {
                throw new InvalidArgumentException('Collection fields must be named schema properties without dots.');
            }
            $path = $prefix.$name;
            $this->properties[$path] = $property;
            $this->collectProperties($property, $path.'.');
        }
    }

    private function object(mixed $value, array $keys, string $keyword): array
    {
        if (! is_array($value) || array_is_list($value) || array_diff(array_keys($value), $keys) !== []) {
            throw new InvalidArgumentException("Malformed {$keyword} declaration.");
        }

        return $value;
    }
}
