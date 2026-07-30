<?php

declare(strict_types=1);

namespace Splicewire\Beam\Read\Contracts;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Read\NullSchemaDataResolver;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;

/**
 * The one narrow new read port (beam-write-pipeline ticket 13, DESIGN §9c): resolve a record to its
 * `Data` class. It is the read-side analog of the write pipeline's
 * {@see SchemaTargetResolver} (record → its schema) — here it is
 * record → its typed projection class.
 *
 * The POLICY is host-owned behind this port, exactly like the write side: a host resolves the Data class
 * from its own convention (an `#[AdminResource]` registry, a `SchemaIdentity` versioned class, a manual
 * map). beam-core ships only the port and a null default ({@see NullSchemaDataResolver});
 * a host binds the real resolver.
 */
interface SchemaDataResolver
{
    /**
     * The `Data` class that projects `$record`, or null when none is resolvable (the hydrator then
     * cannot build a typed projection for it).
     *
     * @return class-string<Data>|null
     */
    public function dataClassFor(object $record): ?string;
}
