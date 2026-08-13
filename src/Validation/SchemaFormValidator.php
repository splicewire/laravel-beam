<?php

namespace Splicewire\Beam\Validation;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Schemastud\JsonNs\Exceptions\UnboundPrefix;
use Schemastud\JsonNs\Exceptions\VocabularyArtifactMissing;
use Schemastud\JsonNs\Vocab\VocabularyValidator;
use Schemastud\JsonNs\Vocabulary;
use Splicewire\Beam\Write\ParticleWriter;
use stdClass;

/**
 * The FORMATTED-error validation path for the public intake door (beam-write-pipeline ticket 04) —
 * relocated down from the dissolved submissions package's `SchemaValidator`.
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
class SchemaFormValidator
{
    public function __construct(
        private ?VocabularyValidator $vocabularies = null,
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
        // drop it — the target shape is validated in place, not by reference.
        if (isset($schema['$id']) && ! str_contains((string) $schema['$id'], '://')) {
            unset($schema['$id']);
        }

        $schemaObject = json_decode(json_encode($schema ?: new stdClass), false);
        $payloadObject = json_decode(json_encode($payload ?: new stdClass), false);

        $result = (new Validator)->validate($payloadObject, $schemaObject);

        $errors = $result->isValid() ? [] : (new ErrorFormatter)->format($result->error());

        foreach ($this->namespaceErrors($payload, $schema) as $pointer => $messages) {
            $errors[$pointer] = [...(array) ($errors[$pointer] ?? []), ...$messages];
        }

        return $errors;
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
