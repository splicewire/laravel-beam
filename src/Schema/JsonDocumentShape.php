<?php

namespace Splicewire\Beam\Schema;

use Splicewire\Beam\Validation\SchemaIntakeValidator;
use stdClass;

/**
 * Spells an array-decoded payload as the JSON document it was: every empty PHP array whose schema
 * position declares `object` (and not `array`) becomes `{}`.
 *
 * Extracted from {@see SchemaIntakeValidator} (beam `3375c92`) so the two places that hand a stored
 * or submitted payload to opis agree on how an empty value is spelled: the intake door, and the
 * migrate-on-read gate in {@see SchemaLadderMigrator}. The walk itself is unchanged.
 */
class JsonDocumentShape
{
    /**
     * The submitted payload as the JSON DOCUMENT it was, with every empty object restored.
     *
     * ## The ambiguity this resolves
     *
     * `{}` and `[]` are different documents in JSON and the SAME value in PHP: `json_decode($json,
     * true)` hands back `[]` for both, and re-encoding it emits `[]`. So a section a submitter simply
     * left blank arrived at opis as an array and was refused against its own `type: object` — while the
     * identical form with one key in that section was accepted. Measured on the flagship's guest intake
     * (ux-demo-convergence `G3-FLAGSHIP-INTAKE-EMPTY-SECTION-422`, 2026-09-12): the SPA posts
     * `{menu:{},equipment:{},processes:{},establishment:{…}}` and got
     * `/menu: The data (array) must match the type: object` for each blank one. The door coerced only
     * the TOP-LEVEL empty payload, so the defect was invisible at the root and fatal one level down.
     *
     * ## Why the SCHEMA decides, not the value
     *
     * An empty PHP array carries no evidence of which document it came from; the schema is the only
     * thing in the room that knows what belongs at that position. So this walks the payload alongside
     * the schema and restores `{}` exactly where the schema says `object` and not `array` — which is
     * also what keeps it from inventing an object where an empty ARRAY was meant, the mirror defect a
     * value-shaped guess would have introduced.
     *
     * A position whose schema declares no `type` is left alone on purpose: `[]` is a valid document
     * there under either reading, and a door that guesses in the absence of a declaration would change
     * what a host's existing traffic means. Local `$ref`s (`#/$defs/…`) are followed so a schema that
     * factors its shapes out is walked like an inline one; a remote `$ref` is left to opis, which is
     * the component that can actually fetch it.
     *
     * @param  array<string, mixed>  $root  the whole schema, for resolving local `$ref` pointers
     */
    public function restore(mixed $value, mixed $schema, ?array $root = null): mixed
    {
        $root ??= is_array($schema) ? $schema : [];
        $schema = $this->dereference($schema, $root);

        if (! is_array($value) || ! is_array($schema)) {
            return $value;
        }

        if ($value === []) {
            return $this->declaresObject($schema, $root) ? new stdClass : $value;
        }

        $list = array_is_list($value);

        foreach ($value as $key => $item) {
            $sub = $list
                ? ($schema['prefixItems'][$key] ?? $schema['items'] ?? null)
                : ($schema['properties'][$key] ?? $this->patternSubschema($schema, (string) $key) ?? $schema['additionalProperties'] ?? null);

            // A combinator branch can be the only place a property is described (`allOf`), so the
            // branches are consulted when the schema's own keywords say nothing about this key.
            foreach ($this->branches($schema, $root) as $branch) {
                if ($sub !== null) {
                    break;
                }

                $sub = $list
                    ? ($branch['prefixItems'][$key] ?? $branch['items'] ?? null)
                    : ($branch['properties'][$key] ?? $this->patternSubschema($branch, (string) $key) ?? null);
            }

            if ($sub !== null) {
                $value[$key] = $this->restore($item, $sub, $root);
            }
        }

        return $value;
    }

    /**
     * Does this schema permit an object here and forbid an array? Only then is an empty array
     * unambiguously an empty OBJECT. A combinator branch saying so is enough — but an array type
     * anywhere in the same schema is enough to leave the value alone.
     *
     * @param  array<string, mixed>  $root
     */
    protected function declaresObject(mixed $schema, array $root): bool
    {
        if (! is_array($schema)) {
            return false;
        }

        $schemas = [$this->dereference($schema, $root), ...$this->branches($schema, $root)];
        $object = false;

        foreach ($schemas as $one) {
            $declared = $one['type'] ?? null;
            $types = is_array($declared) ? $declared : ($declared === null ? [] : [$declared]);

            if (in_array('array', $types, true)) {
                return false;
            }

            $object = $object || in_array('object', $types, true);
        }

        return $object;
    }

    /**
     * The `allOf`/`anyOf`/`oneOf`/`then`/`else` subschemas of one schema, dereferenced. One level: a
     * combinator nested inside a combinator is a shape this door has never been handed, and opis stays
     * the authority on what the payload MEANS — this walk only decides how to spell an empty value.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $root
     * @return list<array<string, mixed>>
     */
    protected function branches(array $schema, array $root): array
    {
        $branches = [];

        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            foreach ((array) ($schema[$keyword] ?? []) as $branch) {
                $branch = $this->dereference($branch, $root);

                if (is_array($branch)) {
                    $branches[] = $branch;
                }
            }
        }

        foreach (['then', 'else'] as $keyword) {
            $branch = $this->dereference($schema[$keyword] ?? null, $root);

            if (is_array($branch)) {
                $branches[] = $branch;
            }
        }

        return $branches;
    }

    /**
     * The first `patternProperties` subschema whose regex matches this key.
     *
     * @param  array<string, mixed>  $schema
     */
    protected function patternSubschema(array $schema, string $key): mixed
    {
        foreach ((array) ($schema['patternProperties'] ?? []) as $pattern => $sub) {
            if (@preg_match('/'.str_replace('/', '\/', (string) $pattern).'/', $key) === 1) {
                return $sub;
            }
        }

        return null;
    }

    /**
     * Follow a LOCAL `$ref` (`#/$defs/Thing`) into the root schema. A remote or unresolvable ref is
     * handed back untouched: opis owns fetching those, and a walk that guessed at one would be
     * deciding the shape of a document it cannot see.
     *
     * @param  array<string, mixed>  $root
     */
    protected function dereference(mixed $schema, array $root, int $depth = 0): mixed
    {
        if (! is_array($schema) || ! is_string($schema['$ref'] ?? null) || $depth > 8) {
            return $schema;
        }

        $ref = $schema['$ref'];

        if (! str_starts_with($ref, '#/')) {
            return $schema;
        }

        $target = $root;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));

            if (! is_array($target) || ! array_key_exists($segment, $target)) {
                return $schema;
            }

            $target = $target[$segment];
        }

        // Sibling keywords alongside a `$ref` stay in force (2020-12), so the resolved shape is merged
        // under them rather than replacing them.
        return $this->dereference(
            is_array($target) ? array_merge($target, array_diff_key($schema, ['$ref' => null])) : $target,
            $root,
            $depth + 1,
        );
    }
}
