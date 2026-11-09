<?php

namespace Splicewire\Beam\Config;

use ReflectionClass;
use Schemastud\DataSchemas\Generators\Generator;
use Splicewire\Beam\Config\Attributes\ConfigKey;

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
 *   when declared), the vocabulary a Config area and a validator read without loading PHP.
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
        $reflection = new ReflectionClass($class);

        foreach (array_keys($schema['properties'] ?? []) as $name) {
            // A JSON property name is a string; an integer key here names no PHP property.
            if (! is_string($name) || ! $reflection->hasProperty($name)) {
                continue;
            }

            $attribute = $reflection->getProperty($name)->getAttributes(ConfigKey::class)[0] ?? null;
            $key = $attribute?->newInstance() ?? new ConfigKey;

            $schema['properties'][$name][self::KEYWORD] = array_filter([
                'ceiling' => $key->ceiling->value,
                'effect' => $key->effect->value,
                'secret' => $key->secret,
                'merge' => $key->merge->value,
                'env' => $key->env,
            ], fn (mixed $value): bool => $value !== null);
        }

        return $schema;
    }
}
