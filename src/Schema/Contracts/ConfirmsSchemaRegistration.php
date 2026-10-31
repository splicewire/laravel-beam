<?php

namespace Splicewire\Beam\Schema\Contracts;

use Splicewire\Beam\Schema\SchemaRegistrationConfirmationData;

/** Optional explicit capability; the base SchemaRegistry contract remains unchanged. */
interface ConfirmsSchemaRegistration
{
    public function registerConfirmed(array $schema, SchemaRegistrationConfirmationData $confirmation): void;
}
