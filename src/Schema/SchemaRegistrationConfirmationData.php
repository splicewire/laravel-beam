<?php

namespace Splicewire\Beam\Schema;

use InvalidArgumentException;
use Splicewire\Beam\Data\BeamData;

/** Explicit author confirmation of one proposed artifact and the published versions shown before freezing it. */
#[\Spatie\TypeScriptTransformer\Attributes\TypeScript]
final class SchemaRegistrationConfirmationData extends BeamData
{
    /** @param array<string, string> $publishedVersions Entry identity => exact publication pin. */
    public function __construct(public string $schemaFingerprint, #[\Schemastud\DataSchemas\Attributes\MapValues('string')] public array $publishedVersions)
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $schemaFingerprint) !== 1) {
            throw new InvalidArgumentException('Schema confirmation needs the proposed artifact fingerprint.');
        }
        foreach ($publishedVersions as $id => $pin) {
            if (! is_string($id) || $id === '' || ! is_string($pin) || $pin === '') {
                throw new InvalidArgumentException('Schema confirmation needs exact published entry/version identities.');
            }
        }
    }

    public function matches(array $schema, array $publishedVersions): bool
    {
        $confirmed = $this->publishedVersions;
        ksort($confirmed, SORT_STRING);
        ksort($publishedVersions, SORT_STRING);

        return hash_equals($this->schemaFingerprint, \Schemastud\DataSchemas\Lifecycle\SchemaFingerprint::of($schema, []))
            && $confirmed === $publishedVersions;
    }
}
