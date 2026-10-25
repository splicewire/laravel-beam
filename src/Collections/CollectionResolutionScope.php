<?php

namespace Splicewire\Beam\Collections;

use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use WeakMap;

/** Request/job-local memo only; resolvers and their host bindings remain transient. */
final class CollectionResolutionScope
{
    /** @var WeakMap<SchemaRegistry, array<string, CollectionDefinition>> */
    private WeakMap $definitions;

    public function __construct()
    {
        $this->definitions = new WeakMap;
    }

    public function get(SchemaRegistry $registry, string $key): ?CollectionDefinition
    {
        return ($this->definitions[$registry] ?? [])[$key] ?? null;
    }

    public function put(SchemaRegistry $registry, string $key, CollectionDefinition $definition): void
    {
        $definitions = $this->definitions[$registry] ?? [];
        $definitions[$key] = $definition;
        $this->definitions[$registry] = $definitions;
    }
}
