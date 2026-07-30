<?php

declare(strict_types=1);

namespace Splicewire\Beam\Read;

use Splicewire\Beam\Read\Contracts\SchemaDataResolver;

/**
 * beam-core's default {@see SchemaDataResolver}: resolves nothing. Record → Data-class mapping is an
 * inherently host-owned policy (an `#[AdminResource]` registry, `SchemaIdentity`, a manual map — none of
 * which beam-core knows), so the headless default returns null and a host binds the real resolver. This
 * lets beam boot headless with the read seam bound but inert until a host supplies its projection policy.
 */
final class NullSchemaDataResolver implements SchemaDataResolver
{
    public function dataClassFor(object $record): ?string
    {
        return null;
    }
}
