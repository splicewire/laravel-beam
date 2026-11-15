<?php

namespace Splicewire\Beam\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A config op at an override tier (ADR-0245 decision 2). These are config ops, NOT DataOverlay ops:
 *
 * - `set` writes a value at a pointer in the tier's layer; it wins over every tier below.
 * - `null` is a `set` whose value is null: a RETAINED null override. The tier keeps an entry, the effective value is
 *   null and provenance names the tier. It is never a deletion.
 * - `unset` means INHERIT: it removes the tier's entry for the pointer, and the ladder refolds the tiers below. It is
 *   recorded as a revision for history and is never sent to DataOverlay.
 *
 * Declared in `src/Enums`, beside the ladder's other enums, so a host's TypeScript tree reflects it (ADR-0245 §3).
 */
#[TypeScript]
enum ConfigOverrideOp: string
{
    case Set = 'set';
    case Null = 'null';
    case Unset = 'unset';
}
