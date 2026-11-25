<?php

namespace Splicewire\Beam\Config\Generator;

use Closure;
use Illuminate\Support\Str;
use Splicewire\Beam\Config\JsonPointer;

/**
 * Infers a Config Section from one root's merged array and its config file's syntax tree (config-sections ticket 06).
 *
 * - **Shape.** An array whose keys are all PHP identifiers is a RECORD and becomes its own Data class; any other
 *   associative array is a MAP (`entries:` at the root, `#[MapValues]` below it) whose entry class is the union of its
 *   entries' keys, a key absent from some entry typed `|Optional`. A list is one value (`#[ArrayItems]` when its items
 *   share a scalar type). A map whose values share no shape is `#[ConfigOpaque]`.
 * - **Types.** The narrowest the syntax PROVES ({@see ConfigFileOutline}), widened by the merged value when that value
 *   does not fit (a host that sets a string where the package wrote an int makes the key `int|string`). A key the file
 *   does not show takes the merged value's type; a bare `null` proves nothing and is `mixed`.
 * - **Behaviour.** Every key is a bare `#[ConfigKey]`: deploy-only and not overridable, the conservative default. The
 *   generator never opts a key in.
 * - **Metadata.** A draft title and description per key (the description harvested from the comment above it) under
 *   `#[ConfigDraft]`; an enum where source compares the key against string literals; a combobox when a string value is
 *   a key of a sibling map, or a `::class` value satisfies a registry's entry type.
 */
class ConfigSectionInferrer
{
    private const DESCRIPTION_CAP = 280;

    /** @var array<string, list<InferredKey>> */
    private array $classes = [];

    /** @var array<string, string> */
    private array $pointers = [];

    private string $base = '';

    private string $root = '';

    /**
     * @param  array<string, list<string|int|float|bool>>  $literals  {@see ConfigLiteralComparisons::in()}
     * @param  (Closure(string): ?string)|null  $registryFor  the registry root whose entry type a class satisfies
     */
    public function __construct(
        private ConfigFileOutline $outline,
        private array $literals = [],
        private ?Closure $registryFor = null,
    ) {}

    /** @param array<array-key, mixed> $merged */
    public function infer(string $root, array $merged, ?string $sectionClass = null): InferredSection
    {
        $this->classes = [];
        $this->pointers = [];
        $this->root = $root;
        $sectionClass ??= Str::studly(str_replace('.', '_', $root)).'Section';
        $this->base = Str::beforeLast($sectionClass, 'Section') ?: $sectionClass;

        $entries = null;
        $this->classes[$sectionClass] = [];
        $this->pointers[$sectionClass] = '';

        if ($merged !== [] && ! self::recordShaped($merged)) {
            $entries = $this->entryClass($this->base.'Entry', $merged, []);
        } else {
            $this->classes[$sectionClass] = $this->record($merged, []);
        }

        return new InferredSection(
            root: $root,
            title: Str::ucfirst(str_replace(['.', '_', '-'], ' ', $root)),
            sectionClass: $sectionClass,
            entries: $entries,
            classes: $this->classes,
            pointers: $this->pointers,
        );
    }

    /**
     * @param  array<array-key, mixed>  $array
     * @param  list<string>  $path
     * @return list<InferredKey>
     */
    private function record(array $array, array $path): array
    {
        $keys = [];

        foreach ($array as $name => $value) {
            $keys[] = $this->key((string) $name, $value, $path, $array);
        }

        return $keys;
    }

    /**
     * @param  list<string>  $path  the record's path
     * @param  array<array-key, mixed>  $siblings
     */
    private function key(string $name, mixed $value, array $path, array $siblings): InferredKey
    {
        $here = [...$path, $name];
        $pointer = JsonPointer::from($here);
        $syntax = $this->outline->at($pointer);
        [$property, $mapName] = $this->mapName($name, $path, $siblings);
        $key = new InferredKey(name: $property, type: 'mixed', env: $syntax['env'] ?? null, mapName: $mapName);
        [$key->title, $key->description] = $this->copy($name, $syntax['comment'] ?? '');

        if (is_array($value) && $value !== [] && ! array_is_list($value)) {
            if (self::recordShaped($value)) {
                $class = $this->base.$this->studly($here).'Config';
                $this->classes[$class] = [];
                $this->pointers[$class] = $pointer;
                $this->classes[$class] = $this->record($value, $here);
                $key->type = $class;

                return $key;
            }

            $key->type = 'array';

            if (array_filter($value, fn (mixed $entry): bool => ! is_array($entry) || array_is_list($entry)) === []) {
                $key->mapValues = $this->entryClass($this->base.$this->studly($here).'Entry', $value, $here);
            } elseif (($token = self::sharedToken($value)) !== null) {
                $key->mapValues = $token;
            } else {
                $key->mapValues = '';
                $key->opaque = 'a map whose values share no shape';
            }

            return $key;
        }

        if (is_array($value)) {
            $key->type = 'array';
            $key->arrayItems = $value === [] ? null : self::sharedToken($value);

            return $key;
        }

        $key->type = self::reconcile($syntax['type'] ?? null, $value);
        $key->enum = $this->enum($here, $value, $key->type);
        $key->widget = $this->widget($name, $value, $syntax['class'] ?? null, $siblings, $path);

        return $key;
    }

    /**
     * The entry class of a map: the union of its entries' keys, each typed across every entry that has it.
     *
     * @param  array<array-key, mixed>  $map
     * @param  list<string>  $path
     */
    private function entryClass(string $class, array $map, array $path): string
    {
        $this->classes[$class] = [];
        $this->pointers[$class] = JsonPointer::from($path);
        $names = [];

        foreach ($map as $entry) {
            foreach (array_keys(is_array($entry) ? $entry : []) as $name) {
                $names[(string) $name] = true;
            }
        }

        $keys = [];

        foreach (array_keys($names) as $name) {
            $present = array_filter($map, fn (mixed $entry): bool => is_array($entry) && array_key_exists($name, $entry));
            $first = array_key_first($present);
            $value = $present[$first][$name];
            $key = $this->key($name, $value, [...$path, (string) $first], $present[$first]);

            if ($key->type !== 'mixed' && ! is_array($value)) {
                foreach ($present as $entry) {
                    $key->type = self::reconcile($key->type, $entry[$name]);
                }
            }

            $key->optional = count($present) < count($map);
            $keys[] = $key;
        }

        $this->classes[$class] = $keys;

        return $class;
    }

    /** @return array{0: string, 1: string} the draft title and description */
    private function copy(string $name, string $comment): array
    {
        $title = Str::ucfirst(str_replace(['_', '-'], ' ', $name));
        $paragraph = trim((string) preg_replace('/\s+/', ' ', explode("\n\n", $comment)[0]));

        if ($paragraph === '') {
            return [$title, sprintf('The `%s` setting.', $name)];
        }

        if (mb_strlen($paragraph) > self::DESCRIPTION_CAP) {
            $cut = mb_substr($paragraph, 0, self::DESCRIPTION_CAP);
            $sentence = mb_strrpos($cut, '. ');
            $paragraph = $sentence !== false && $sentence > 80 ? mb_substr($cut, 0, $sentence + 1) : rtrim(mb_substr($cut, 0, (int) mb_strrpos($cut, ' '))).'…';
        }

        return [$title, $paragraph];
    }

    /**
     * The enum for a key the source compares against literals, or null. Only a key whose type (null removed) is exactly
     * ONE of `string`, `int` or `float` gets one, made of the literals of that type alone (an int is a number, so it is
     * admitted for a float key): an enum never mixes types, a union such as `int|string` (a bare `env()`) gets none
     * because its runtime value may be either, and `bool` gets none because its type already has two values
     * (review-r1, 2026-11-16: ints and floats). The default leads.
     *
     * @param  list<string>  $here
     * @return list<string|int|float>|null
     */
    private function enum(array $here, mixed $value, string $type): ?array
    {
        $literals = $this->literals[implode('.', $here)] ?? [];
        $scalar = ltrim($type, '?');

        if ($literals === [] || ! in_array($scalar, ['string', 'int', 'float'], true)) {
            return null;
        }

        $fits = fn (mixed $literal): bool => match ($scalar) {
            'string' => is_string($literal),
            'int' => is_int($literal),
            'float' => is_int($literal) || is_float($literal),
        };
        $kept = array_values(array_filter($literals, $fits));

        if ($kept === [] || ! $fits($value)) {
            return null;
        }

        $enum = [$value];

        foreach ($kept as $literal) {
            if (! in_array($literal, $enum, true)) {
                $enum[] = $literal;
            }
        }

        return $enum;
    }

    /**
     * @param  array<array-key, mixed>  $siblings
     * @param  list<string>  $path
     * @return array<string, string>|null
     */
    private function widget(string $name, mixed $value, ?string $class, array $siblings, array $path): ?array
    {
        if (is_string($value) && $value !== '') {
            foreach ($siblings as $sibling => $map) {
                if ((string) $sibling !== $name && is_array($map) && $map !== [] && ! array_is_list($map) && ! self::isRecord($map) && array_key_exists($value, $map)) {
                    return ['keysOf' => JsonPointer::from([...$path, (string) $sibling])];
                }
            }
        }

        if ($class !== null && $this->registryFor !== null && ($registry = ($this->registryFor)($class)) !== null) {
            return ['registry' => $registry];
        }

        return null;
    }

    /** @param list<string> $path */
    private function studly(array $path): string
    {
        return implode('', array_map(fn (string $segment): string => Str::studly($segment), $path));
    }

    /**
     * The proven type widened by the observed value: a value the type does not admit adds its own type.
     */
    private static function reconcile(?string $proven, mixed $value): string
    {
        $observed = self::token($value);

        if ($proven === null || $proven === 'null') {
            return $observed === null || $observed === 'null' ? 'mixed' : $observed;
        }

        if ($proven === 'mixed') {
            return 'mixed';
        }

        $tokens = self::tokens($proven);

        if ($observed !== null && ! in_array($observed, $tokens, true) && ! ($observed === 'int' && in_array('float', $tokens, true))) {
            $tokens[] = $observed;
        }

        return self::declaration($tokens);
    }

    /** @return list<string> */
    private static function tokens(string $type): array
    {
        $tokens = [];

        foreach (explode('|', $type) as $part) {
            if (str_starts_with($part, '?')) {
                $tokens[] = 'null';
                $part = substr($part, 1);
            }

            $tokens[] = $part;
        }

        return array_values(array_unique($tokens));
    }

    /** @param list<string> $tokens */
    private static function declaration(array $tokens): string
    {
        $tokens = array_values(array_unique($tokens));
        $nullable = in_array('null', $tokens, true);
        $rest = array_values(array_diff($tokens, ['null']));
        sort($rest);

        if ($rest === []) {
            return 'mixed';
        }

        if (count($rest) === 1) {
            return ($nullable ? '?' : '').$rest[0];
        }

        return implode('|', $nullable ? [...$rest, 'null'] : $rest);
    }

    private static function token(mixed $value): ?string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_string($value) => 'string',
            default => null,
        };
    }

    /**
     * The JSON Schema type token every value shares, or null.
     *
     * @param  array<array-key, mixed>  $values
     */
    private static function sharedToken(array $values): ?string
    {
        $tokens = array_unique(array_map(fn (mixed $value): ?string => match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            default => null,
        }, array_values($values)));

        return count($tokens) === 1 && reset($tokens) !== null ? (string) reset($tokens) : null;
    }

    /** @param array<array-key, mixed> $array */
    public static function isRecord(array $array): bool
    {
        foreach (array_keys($array) as $key) {
            if (! is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an array is a RECORD (fixed, named fields → its own Data class) rather than a MAP (host-chosen keys,
     * uniform values → `entries:` / `#[MapValues]`), config-sections ticket 13 Part 2.
     *
     * An all-identifier array is a record, unchanged ({@see isRecord}). A non-identifier key no longer forces a map:
     * when EVERY key reduces to a distinct identifier (so a `#[MapName]` can carry its wire name) and the array is
     * neither a uniform scalar map nor a host-keyed collection, it is a heterogeneous record with mapped fields — the
     * shape of a section like `beam.notifications` (`recipient-kinds` beside `listen`, `resources`). Two shapes stay
     * MAPS even with mappable keys: a uniform scalar map, where every value shares one JSON scalar token
     * (`beam.commerce billing.flows`, a `kind => class` registry); and a COLLECTION, where every value is itself a
     * nested associative array ({@see isCollection}) — host-chosen entry keys over uniform-shape objects, like
     * `app.chat`'s `models` (`gpt-3.5-turbo => {provider, token_limit, …}`), which is a `#[MapValues]` entry class, not
     * a Data class per model. An array with a key that reduces to no identifier is also a map (its unmappable key
     * becomes a legible error only if it then has to name a property, see {@see mapName}).
     *
     * @param  array<array-key, mixed>  $array
     */
    private static function recordShaped(array $array): bool
    {
        if (self::isRecord($array)) {
            return true;
        }

        if (self::sharedToken($array) !== null || self::isCollection($array)) {
            return false;
        }

        foreach (array_keys($array) as $key) {
            if (self::identify((string) $key) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an array is a host-keyed COLLECTION: every value is a non-empty associative array (a nested record or
     * map entry), so the keys are host-chosen entries over a uniform value shape, not a record's fixed fields. Such an
     * array is a MAP (one `#[MapValues]` entry class), never a Data class per key — the classification the base
     * `isRecord` gave every non-identifier-keyed array, preserved here for a collection whose keys happen to reduce to
     * identifiers (config-sections ticket 13).
     *
     * @param  array<array-key, mixed>  $array
     */
    private static function isCollection(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        foreach ($array as $value) {
            if (! is_array($value) || $value === [] || array_is_list($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The property name and wire name a config key becomes (config-sections ticket 13, Parts 1 and 2). An identifier
     * answers to itself with no mapping. A non-identifier key reduces to a camelCase property and returns its wire
     * name, so the emitter writes `#[MapName]` and data-schemas keys the schema on the same wire name. A key that
     * reduces to no identifier, or whose mapped name a sibling key also takes, throws {@see ConfigSectionInferenceError}
     * naming the root, the record's pointer and the key — the three facts nette's "Value '0' is not valid name" omits.
     *
     * @param  list<string>  $path  the enclosing record's path, for the pointer in an error
     * @param  array<array-key, mixed>  $siblings  the record's keys, to catch a mapped-name collision
     * @return array{0: string, 1: ?string}  [property name, wire name or null]
     */
    private function mapName(string $key, array $path, array $siblings): array
    {
        $name = self::identify($key);

        if ($name === null) {
            throw new ConfigSectionInferenceError(
                $this->root,
                JsonPointer::from($path),
                $key,
                'does not reduce to a PHP identifier (it has no leading letter or underscore to name a property)',
            );
        }

        if ($name === $key) {
            return [$key, null];
        }

        foreach (array_keys($siblings) as $sibling) {
            if ((string) $sibling !== $key && self::identify((string) $sibling) === $name) {
                throw new ConfigSectionInferenceError(
                    $this->root,
                    JsonPointer::from($path),
                    $key,
                    sprintf('maps to the property `%s`, which the sibling key `%s` also takes', $name, $sibling),
                );
            }
        }

        return [$name, $key];
    }

    /** The PHP identifier a config key reduces to (itself when it already is one), or null when it reduces to none. */
    private static function identify(string $key): ?string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1) {
            return $key;
        }

        $name = Str::camel(str_replace(['-', '.', ' ', '/'], '_', $key));

        return $name !== '' && preg_match('/^[A-Za-z_]/', $name) === 1 ? $name : null;
    }
}
