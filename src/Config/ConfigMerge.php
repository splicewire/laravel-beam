<?php

namespace Splicewire\Beam\Config;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How an override combines with the value it covers (ADR-0242). Declared per key; inert until an override tier
 * exists, and recorded now so a section's declaration does not change when one does.
 */
#[TypeScript]
enum ConfigMerge: string
{
    /** The override replaces the value below it. */
    case Replace = 'replace';

    /** Maps merge recursively; the override wins key by key. */
    case Deep = 'deep';

    /** The override may add map entries but never replaces the package's own (a model catalog). */
    case Extend = 'extend';
}
