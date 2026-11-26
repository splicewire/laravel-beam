<?php

namespace Splicewire\Beam\Config;

use ReflectionClass;
use Schemastud\DataSchemas\Generators\Generator;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Config\Attributes\ConfigOpaque;

/**
 * A Config Section's JSON Schema (ADR-0245), generated through the host's CONFIGURED data-schemas generator, the
 * one `schemas:generate` runs, so titles, descriptions, enums, ranges and `x-widget` controls come from the same
 * attributes every other Data class uses.
 *
 * Two things the generic generator cannot know are added here:
 *
 * - **The root's shape.** A map-shaped root (`entries:`) is `{type: object, additionalProperties: <entry>}`. PHP
 *   cannot type a map whose keys a host chooses as properties, and the section class declares none.
 * - **Ladder behaviour.** Each declared key carries `x-beam-config: {ceiling, effect, secret, merge}` (and `env`
 *   when declared, `opaque` for a `#[ConfigOpaque]` key), the vocabulary a Config area and a validator read without
 *   loading PHP.
 *
 * The committed copy is written by `splicewire:beam:config:schemas` and compared, never written, by
 * {@see self::drift()}.
 */
class ConfigSectionSchema
{
    public const KEYWORD = 'x-beam-config';

    public function __construct(private Generator $generator) {}

    /**
     * @param  class-string<ConfigSection>  $section
     * @return array<string, mixed>
     */
    public function for(string $section): array
    {
        $declaration = $section::declaration();
        $keys = $this->annotated($this->generator->generate(new ReflectionClass($section::keyClass())), $section::keyClass());

        $defs = $keys['$defs'] ?? null;
        unset($keys['$schema'], $keys['$id'], $keys['$defs']);

        if ($declaration->entries !== null) {
            unset($keys['title']);
            $body = ['type' => 'object', 'additionalProperties' => $keys];
        } else {
            unset($keys['title'], $keys['description']);
            $body = $keys;
        }

        return array_filter([
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => self::id($declaration->root),
            'title' => $declaration->title,
            'description' => $declaration->description !== '' ? $declaration->description : null,
            ...$body,
            '$defs' => $defs,
            self::KEYWORD.'-root' => $declaration->root,
        ], fn (mixed $value): bool => $value !== null);
    }

    public static function id(string $root): string
    {
        return 'config-sections/'.$root;
    }

    /** The committed file for a root, under the host's schema directory. */
    public static function path(string $root, ?string $directory = null): string
    {
        return rtrim($directory ?? resource_path('schemas/config-sections'), '/').'/'.$root.'.schema.json';
    }

    /** @param array<string, mixed> $schema */
    public static function encode(array $schema): string
    {
        return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * Each registered section whose committed schema is missing or differs from a fresh generation, by root,
     * with the reason. Reads only; the verification test and `--check` both stand on this.
     *
     * @param  list<RegisteredConfigSection>  $sections
     * @return array<string, string>
     */
    public function drift(array $sections, ?string $directory = null): array
    {
        $drift = [];

        foreach ($sections as $registered) {
            $path = self::path($registered->root(), $directory);

            if (! is_file($path)) {
                $drift[$registered->root()] = "missing: {$path}";

                continue;
            }

            if (file_get_contents($path) !== self::encode($this->for($registered->section))) {
                $drift[$registered->root()] = "stale: {$path}";
            }
        }

        return $drift;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function annotated(array $schema, string $class): array
    {
        // Wire name => [PHP property, Spatie declaration]. The generator keyed the document on the WIRE name — for a
        // `#[MapName]` key the OUTPUT mapped name, the axis `for()` generates on (default, non-request mode). Resolved
        // through the one shared wire-name source, so coverage, the schema and the emitter can never disagree about
        // what a key is called (ticket 13, Part 2, condition 2).
        $byWire = ConfigWireNames::map($class);

        $required = array_values(array_filter(
            $schema['required'] ?? [],
            static fn (mixed $name): bool => is_string($name),
        ));

        foreach (array_keys($schema['properties'] ?? []) as $name) {
            // A JSON property name is a string; an integer key here names no PHP property.
            if (! is_string($name) || ! isset($byWire[$name])) {
                continue;
            }

            [$property, $spatie] = $byWire[$name];

            // Part 4 (Option B). `for()` generates in the default (non-request) mode, where the generator marks every
            // promoted property required — `isRequired()` exempts only `Optional`/`Lazy`, not a plain nullable or a
            // defaulted one. A Config area must not demand a key the package leaves nullable or defaulted, so its wire
            // name is pruned from `required`. Nullability is read off the type; the default is asked of Spatie, since
            // raw reflection answers `false` for a promoted parameter's default (the common case).
            if (($property->getType()?->allowsNull() ?? false) || ($spatie?->hasDefaultValue ?? false)) {
                $required = array_values(array_filter($required, static fn (string $required): bool => $required !== $name));
            }

            // Part 4 (Option B, second shape). A `#[MapValues]` map with no entries is PHP `[]`, which JSON-encodes as
            // an array, not an object, and fails the map's `type: object`. Admit the empty array alongside the object
            // so the package default and a host that clears the map both validate — the anyOf coord accepted. Detected
            // by schema-valued `additionalProperties`, which typed maps carry. Boolean true/false do not declare a
            // value schema and may belong to inline records from the host's configured generator.
            $property_schema = $schema['properties'][$name];

            if (in_array('object', (array) ($property_schema['type'] ?? []), true) && is_array($property_schema['additionalProperties'] ?? null)) {
                $schema['properties'][$name] = ['anyOf' => [$property_schema, ['type' => 'array', 'maxItems' => 0]]];
            }

            $attribute = $property->getAttributes(ConfigKey::class)[0] ?? null;
            $key = $attribute?->newInstance() ?? new ConfigKey;

            // Attached at the property level, beside `anyOf` when the map carries one, never inside a branch — where
            // every reader of the keyword looks for it (the Part 4 anyOf reader audit, LANE-B-2330).
            $schema['properties'][$name][self::KEYWORD] = array_filter([
                'ceiling' => $key->ceiling->value,
                'effect' => $key->effect->value,
                'secret' => $key->secret,
                'merge' => $key->merge->value,
                'env' => $key->env,
                // Only an opaque key carries it, so no other committed schema changes (ticket 06).
                'opaque' => $property->getAttributes(ConfigOpaque::class) === [] ? null : true,
            ], fn (mixed $value): bool => $value !== null);
        }

        if ($required === []) {
            unset($schema['required']);
        } else {
            $schema['required'] = $required;
        }

        return $schema;
    }
}
