<?php

namespace Splicewire\Beam\Tests\Validation;

use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\JsonNs\Vocab\VocabularyRegistry;
use Schemastud\JsonNs\Vocab\VocabularyValidator;
use Splicewire\Beam\Schema\BeamSchemaRegistry;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Validation\SchemaFormValidator;

/**
 * The namespace-aware formatted validation path (beam-namespace-wiring ticket 02): a schema
 * declaring `@namespace`/`@namespaced` content has each namespaced payload subtree ADDITIONALLY
 * enforced against its namespace's `$vocabulary` via the json-ns shim, violations merging into
 * the SAME `{pointer: [messages]}` map. A schema with no namespace content takes the exact
 * pre-ticket path.
 */
class SchemaFormValidatorTest extends TestCase
{
    private const VOCAB_URI = 'https://schemas.splicewire.app/splice/grounding-test';

    private function namespacedValidator(): SchemaFormValidator
    {
        $registry = VocabularyRegistry::make()->registerJson(self::VOCAB_URI, json_encode([
            'type' => 'object',
            'required' => ['sources'],
            'properties' => ['sources' => ['type' => 'array', 'minItems' => 1]],
        ]));

        return new SchemaFormValidator(new VocabularyValidator($registry));
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
        $door = new SchemaFormValidator;

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
