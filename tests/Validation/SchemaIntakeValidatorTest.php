<?php

namespace Splicewire\Beam\Tests\Validation;

use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\JsonNs\Vocab\VocabularyRegistry;
use Schemastud\JsonNs\Vocab\VocabularyValidator;
use Splicewire\Beam\Schema\BeamSchemaRegistry;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Validation\SchemaIntakeValidator;

/**
 * The namespace-aware formatted validation path (beam-namespace-wiring ticket 02): a schema
 * declaring `@namespace`/`@namespaced` content has each namespaced payload subtree ADDITIONALLY
 * enforced against its namespace's `$vocabulary` via the json-ns shim, violations merging into
 * the SAME `{pointer: [messages]}` map. A schema with no namespace content takes the exact
 * pre-ticket path.
 */
class SchemaIntakeValidatorTest extends TestCase
{
    private const VOCAB_URI = 'https://beam.test/schemas/splice/grounding-test';

    private function namespacedValidator(): SchemaIntakeValidator
    {
        $registry = VocabularyRegistry::make()->registerJson(self::VOCAB_URI, json_encode([
            'type' => 'object',
            'required' => ['sources'],
            'properties' => ['sources' => ['type' => 'array', 'minItems' => 1]],
        ]));

        return new SchemaIntakeValidator(new VocabularyValidator($registry));
    }

    private function namespacedSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['title'],
            'properties' => [
                'title' => ['type' => 'string'],
                'splice:grounding' => ['type' => 'object'],
            ],
            '@namespace' => ['splice' => self::VOCAB_URI],
        ];
    }

    public function test_a_non_namespaced_schema_behaves_exactly_as_before(): void
    {
        $validator = $this->namespacedValidator();
        $schema = ['type' => 'object', 'required' => ['title'], 'properties' => ['title' => ['type' => 'string']]];

        $this->assertSame([], $validator->validate(['title' => 'ok'], $schema));
        $this->assertNotSame([], $validator->validate([], $schema));
    }

    public function test_a_conforming_namespaced_payload_passes_both_passes(): void
    {
        $errors = $this->namespacedValidator()->validate(
            ['title' => 'ok', 'splice:grounding' => ['sources' => ['ctx://a']]],
            $this->namespacedSchema(),
        );

        $this->assertSame([], $errors);
    }

    public function test_a_vocabulary_violation_merges_into_the_formatted_error_map(): void
    {
        // Structurally fine (title present, splice:grounding is an object) — but the namespaced
        // subtree violates its vocabulary (`sources` missing).
        $errors = $this->namespacedValidator()->validate(
            ['title' => 'ok', 'splice:grounding' => ['nope' => true]],
            $this->namespacedSchema(),
        );

        $this->assertNotSame([], $errors);
        // The formatter surfaces the vocabulary sub-error's own message (per-field UX).
        $this->assertStringContainsString('sources', json_encode($errors));
    }

    public function test_a_structural_and_a_vocabulary_error_share_one_error_map(): void
    {
        // title missing (structural) AND the subtree violates its vocabulary — one coherent map.
        $errors = $this->namespacedValidator()->validate(
            ['splice:grounding' => ['nope' => true]],
            $this->namespacedSchema(),
        );

        $this->assertNotSame([], $errors);
        $encoded = json_encode($errors);
        $this->assertStringContainsString('title', $encoded);
        $this->assertStringContainsString('sources', $encoded);
    }

    public function test_an_unenforceable_declaration_refuses_with_a_formatted_error_not_a_500(): void
    {
        // The schema binds a prefix the payload's subtree key does NOT use — the payload's own
        // `mystery:` prefix is unbound, so scoping throws inside the engine. Gate parity
        // (ADR-0193 §4): the door surfaces a formatted refusal, never an uncaught exception.
        $errors = $this->namespacedValidator()->validate(
            ['title' => 'ok', 'mystery:thing' => ['a' => 1]],
            $this->namespacedSchema(),
        );

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('mystery', json_encode($errors));
    }

    /**
     * ## An empty JSON object survives the round-trip through PHP
     *
     * `{}` and `[]` are different documents in JSON and the same value in PHP: `json_decode($json,
     * true)` gives `[]` for both, and re-encoding it produces `[]`. So a submitted section the author
     * simply left blank arrived at opis as an array and was refused against its own `type: object`
     * property — while the identical form with one key in that section was accepted.
     *
     * Measured on the flagship SPA (ux-demo-convergence `G3-FLAGSHIP-INTAKE-EMPTY-SECTION-422`,
     * 2026-09-12): the guest intake posts `{menu:{},equipment:{},processes:{},establishment:{…}}` and
     * got `422 /menu: The data (array) must match the type: object` for each blank section.
     *
     * The schema is what disambiguates — it is the only thing in the room that knows whether a given
     * position is an object or an array — so the coercion is schema-driven and reaches every position
     * the schema describes, not just the root.
     */
    public function test_a_blank_optional_section_is_an_empty_object_where_the_schema_says_object(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['establishment'],
            'properties' => [
                'establishment' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string']]],
                'menu' => ['type' => 'object'],
                'equipment' => ['type' => 'object'],
                'processes' => ['type' => 'object'],
            ],
        ];

        // Exactly what `json_decode($request->getContent(), true)` yields for
        // {"establishment":{"name":"Bea's"},"menu":{},"equipment":{},"processes":{}}.
        $payload = ['establishment' => ['name' => "Bea's"], 'menu' => [], 'equipment' => [], 'processes' => []];

        $this->assertSame([], (new SchemaIntakeValidator)->validate($payload, $schema));
    }

    /** The same fact one level deeper: nesting is not a special case, it is the general one. */
    public function test_the_coercion_reaches_a_nested_object_property(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'establishment' => [
                    'type' => 'object',
                    'properties' => ['hours' => ['type' => 'object'], 'name' => ['type' => 'string']],
                ],
                'sections' => ['type' => 'array', 'items' => ['type' => 'object']],
            ],
        ];

        $payload = ['establishment' => ['name' => 'B', 'hours' => []], 'sections' => [[], []]];

        $this->assertSame([], (new SchemaIntakeValidator)->validate($payload, $schema));
    }

    /**
     * The coercion must not invent an object where the schema wanted an array — the mirror defect, and
     * the reason this reads the schema rather than guessing from the value's emptiness.
     */
    public function test_an_empty_array_stays_an_array_where_the_schema_says_array(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'tags' => ['type' => 'array'],
                'notes' => ['type' => 'array', 'minItems' => 1],
            ],
        ];

        $this->assertSame([], (new SchemaIntakeValidator)->validate(['tags' => []], $schema));

        // And a genuinely invalid one is still refused, with its own message rather than a type error.
        $errors = (new SchemaIntakeValidator)->validate(['notes' => []], $schema);
        $this->assertNotSame([], $errors);
        $this->assertStringNotContainsString('must match the type: array', json_encode($errors));
    }

    /** A blank section is not an excuse to skip the section's own required content. */
    public function test_a_required_property_inside_a_coerced_object_is_still_enforced(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['establishment' => ['type' => 'object', 'required' => ['name']]],
        ];

        $errors = (new SchemaIntakeValidator)->validate(['establishment' => []], $schema);

        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('name', json_encode($errors));
    }

    public function test_the_container_chain_resolves_vocabularies_through_the_schema_registry(): void
    {
        // The FULL production chain, no hand-built registry: the vocabulary artifact lives in a
        // real schema-registry tier (versioned $id), the host binds SchemaRegistry, and the
        // validator resolves through JsonNsServiceProvider's registry-backed binding.
        $dir = sys_get_temp_dir().'/sfv-fleet-'.uniqid();
        @mkdir($dir, 0775, true);

        (new FilesystemSchemaRegistry($dir))->register([
            '$id' => self::VOCAB_URI.'/1',
            'type' => 'object',
            'required' => ['sources'],
            'properties' => ['sources' => ['type' => 'array', 'minItems' => 1]],
        ]);

        $this->app->bind(SchemaRegistry::class, fn () => new BeamSchemaRegistry(
            ['file'],
            ['file' => fn () => new FilesystemSchemaRegistry($dir)],
        ));
        $this->app->forgetInstance(VocabularyRegistry::class);

        // No injected engine — the door resolves the container's registry-backed binding.
        $door = new SchemaIntakeValidator;

        $this->assertNotSame([], $door->validate(
            ['title' => 'ok', 'splice:grounding' => ['nope' => true]],
            $this->namespacedSchema(),
        ));
        $this->assertSame([], $door->validate(
            ['title' => 'ok', 'splice:grounding' => ['sources' => ['ctx://a']]],
            $this->namespacedSchema(),
        ));

        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }
}
