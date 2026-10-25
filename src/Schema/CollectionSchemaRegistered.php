<?php

namespace Splicewire\Beam\Schema;

/** A new, database-backed Collection schema version has been registered. */
final class CollectionSchemaRegistered
{
    public function __construct(public readonly string $stem) {}
}
