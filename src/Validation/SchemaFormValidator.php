<?php

declare(strict_types=1);

namespace Splicewire\Beam\Validation;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Write\RecordWriter;
use stdClass;

/**
 * The FORMATTED-error validation path for the public intake door (beam-write-pipeline ticket 04) —
 * relocated down from the dissolved submissions package's `SchemaValidator`.
 *
 * Two validation gates deliberately coexist (DESIGN §7 L10): the boolean {@see AcceptanceGate}
 * the migration ladder and {@see RecordWriter} use, and THIS formatted path,
 * which returns a field-keyed error map so the HTTP layer can render a 422 body. It is the door's
 * concern (a human submitting a form deserves per-field errors), not the pipeline's.
 */
final class SchemaFormValidator
{
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

        if ($result->isValid()) {
            return [];
        }

        return (new ErrorFormatter)->format($result->error());
    }
}
