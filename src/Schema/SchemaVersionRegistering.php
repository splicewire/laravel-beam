<?php

namespace Splicewire\Beam\Schema;

use Schemastud\DataSchemas\Contracts\SchemaRegistry;

/** Synchronous admission before a NEW runtime artifact persists, inside its schema-anchor transaction. */
final readonly class SchemaVersionRegistering
{
    /** @param list<array<string, mixed>> $versions Previously frozen artifacts of this exact stem. */
    public function __construct(
        public array $schema,
        public array $versions,
        public SchemaRegistry $registry,
        public ?string $connection,
        public ?SchemaRegistrationConfirmationData $confirmation = null,
    ) {}
}
