<?php

namespace Splicewire\Beam\Tests\Validation;

use Schemastud\JsonNs\Vocab\VocabularyRegistry;
use Schemastud\JsonNs\Vocab\VocabularyValidator;
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
}
