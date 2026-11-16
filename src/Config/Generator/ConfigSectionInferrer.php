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
        $sectionClass ??= Str::studly(str_replace('.', '_', $root)).'Section';
        $this->base = Str::beforeLast($sectionClass, 'Section') ?: $sectionClass;

        $entries = null;
        $this->classes[$sectionClass] = [];
        $this->pointers[$sectionClass] = '';

        if ($merged !== [] && ! self::isRecord($merged)) {
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
        $key = new InferredKey(name: $name, type: 'mixed', env: $syntax['env'] ?? null);
        [$key->title, $key->description] = $this->copy($name, $syntax['comment'] ?? '');

        if (is_array($value) && $value !== [] && ! array_is_list($value)) {
            if (self::isRecord($value)) {
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
}
