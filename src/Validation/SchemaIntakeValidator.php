<?php

namespace Splicewire\Beam\Validation;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Schemastud\DataSchemas\Support\OpisSchema;
use Schemastud\JsonNs\Exceptions\UnboundPrefix;
use Schemastud\JsonNs\Exceptions\VocabularyArtifactMissing;
use Schemastud\JsonNs\Vocab\VocabularyValidator;
use Schemastud\JsonNs\Vocabulary;
use Splicewire\Beam\Http\PublicIntakeController;
use Splicewire\Beam\Write\ParticleWriter;
use stdClass;

/**
 * The FORMATTED-error validation path for the public intake door (beam-write-pipeline ticket 04) —
 * relocated down from the dissolved submissions package's `SchemaValidator`.
 *
 * Named `SchemaFormValidator` until beam-facade ticket 52. There is no such thing as a form schema
 * (ticket 41, the owner's: any schema can be used as a form — a form is a rendering/intake MODE of a
 * schema, never a class of schema), so the old name carried a category the dissolved
 * `splicewire/laravel-schema-forms` package invented and outlived. `Intake` is the vocabulary the
 * door already speaks: {@see PublicIntakeController}, `IntakeProvenance`,
 * `PublicIntakeWriteGate`, `config('beam.core.intake')`.
 *
 * Two validation gates deliberately coexist (DESIGN §7 L10): the boolean {@see AcceptanceGate}
 * the migration ladder and {@see ParticleWriter} use, and THIS formatted path,
 * which returns a field-keyed error map so the HTTP layer can render a 422 body. It is the door's
 * concern (a human submitting a form deserves per-field errors), not the pipeline's.
 *
 * Namespace-aware (beam-namespace-wiring ticket 02): when the schema declares `@namespace` /
 * `@namespaced` content, the submitted document's namespaced subtrees are ADDITIONALLY enforced
 * against their namespaces' `$vocabulary` schemas via the json-ns {@see VocabularyValidator}
 * (registry-backed, JN-16), and any violations merge into the SAME formatted error map — one
 * coherent error shape out of {@see validate()}, never a second channel. A schema with no
 * namespace content behaves byte-for-byte as before. The schema's declarations govern the
 * payload: they are overlaid onto the submitted document before scoping, so an `@namespaced`
 * scope pointer addresses the PAYLOAD's shape.
 */
class SchemaIntakeValidator
{
    /**
     * How many violations one pass may report (beam-facade ticket 51). opis defaults to **1**, and
     * nothing in the estate had ever raised it — so a payload breaking two constraints showed the
     * submitter one field, and the next attempt showed the other. That is not the field-keyed error
     * map this door promises.
     *
     * BOUNDED rather than unbounded on purpose: a large array payload against a strict schema can
     * generate an error per element, and the 422 body is rendered to a human. Twenty-five is well
     * past any hand-filled form and far short of a runaway.
     */
    public const MAX_ERRORS = 25;

    public function __construct(
        private ?VocabularyValidator $vocabularies = null,
        private int $maxErrors = self::MAX_ERRORS,
    ) {}

    /**
     * Validate `$payload` against `$schema`, returning an empty array when valid or an opis-formatted
     * `{pointer: [messages]}` error map when not.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function validate(array $payload, array $schema): array
    {
        // opis requires an absolute `$id`; a relative one (a bare form ref) would make it choke, so
        // drop it — the target shape is validated in place, not by reference. Shared with the boolean
        // gate rather than duplicated here, which is what keeps the two doors in parity.
        $schema = OpisSchema::withoutRelativeId($schema);

        $schemaObject = json_decode(json_encode($schema ?: new stdClass), false);

        // `?:` still covers the ROOT: an entirely empty submission is an empty object at every door,
        // whatever the root schema says. Everything below the root is the schema's call — see
        // {@see asJsonDocument()}.
        $payloadObject = json_decode(json_encode($this->asJsonDocument($payload, $schema, $schema) ?: new stdClass), false);

        $validator = new Validator;
        $validator->setMaxErrors($this->maxErrors);

        $result = $validator->validate($payloadObject, $schemaObject);

        $errors = $result->isValid() ? [] : (new ErrorFormatter)->format($result->error());

        foreach ($this->namespaceErrors($payload, $schema) as $pointer => $messages) {
            $errors[$pointer] = [...(array) ($errors[$pointer] ?? []), ...$messages];
        }

        return $errors;
    }

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
    protected function asJsonDocument(mixed $value, mixed $schema, array $root): mixed
    {
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
                $value[$key] = $this->asJsonDocument($item, $sub, $root);
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

    /**
     * The per-namespace `$vocabulary` violations of the payload's namespaced subtrees, as an
     * opis-formatted `{pointer: [messages]}` map — empty when the schema declares no namespace
     * content (the additive guarantee: non-namespaced schemas take the exact pre-ticket path).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $schema
     * @return array<string, array<int, string>>
     */
    protected function namespaceErrors(array $payload, array $schema): array
    {
        $declarations = Vocabulary::declarationsOf($schema);

        if ($declarations === []) {
            return [];
        }

        $validator = $this->vocabularies();

        if ($validator === null) {
            return [];
        }

        $formatted = [];
        $formatter = new ErrorFormatter;

        try {
            // The schema's declarations govern the submitted document (declarations win on key clash).
            $violations = $validator->validate($declarations + $payload);
        } catch (UnboundPrefix|VocabularyArtifactMissing $e) {
            // An UNENFORCEABLE declaration refuses at BOTH doors (gate parity, ADR-0193 §4) — here
            // as a formatted error with the reason, never an uncaught 500.
            return ['/' => [$e->getMessage()]];
        }

        foreach ($violations as $error) {
            foreach ($formatter->format($error) as $pointer => $messages) {
                $formatted[$pointer] = [...(array) ($formatted[$pointer] ?? []), ...(array) $messages];
            }
        }

        return $formatted;
    }

    /**
     * The vocabulary enforcement engine: the injected instance, else the host container's
     * registry-backed binding (JsonNsServiceProvider, JN-16). Null only when no binding exists —
     * a host without json-ns wiring keeps the plain structural pass.
     */
    protected function vocabularies(): ?VocabularyValidator
    {
        if ($this->vocabularies !== null) {
            return $this->vocabularies;
        }

        if (function_exists('app') && app()->bound(VocabularyValidator::class)) {
            return $this->vocabularies = app(VocabularyValidator::class);
        }

        return null;
    }
}
