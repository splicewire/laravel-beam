<?php

namespace Splicewire\Beam\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A rung of the Override Ladder, bottom-up: `package default → host config/env → operator override → tenant
 * override` (ADR-0245). The case order IS the ladder order; {@see rank()} reads it.
 *
 * `Provider` is not a tier anyone writes. It names a value a service provider wrote into `config()` at boot,
 * which neither config file holds — the ladder reports it so such a key does not lie about its source
 * (config-sections ticket 01, finding 1). It sits between host and operator because it runs after both files
 * merge and before any override applies.
 *
 * Ticket 02 folds `Package`, `Host` and `Provider` only. `Operator` and `Tenant` are declared here so a section
 * can name them as its ceiling today; their rows arrive with the override store.
 */
#[TypeScript]
enum ConfigTier: string
{
    case Package = 'package';
    case Host = 'host';
    case Provider = 'provider';
    case Operator = 'operator';
    case Tenant = 'tenant';

    public function rank(): int
    {
        return match ($this) {
            self::Package => 0,
            self::Host => 1,
            self::Provider => 2,
            self::Operator => 3,
            self::Tenant => 4,
        };
    }

    /** Whether a key whose ceiling is this tier may be overridden at `$tier`. */
    public function admits(self $tier): bool
    {
        return $tier->rank() <= $this->rank();
    }

    /** Whether a key whose ceiling is this tier can be overridden at runtime at all. */
    public function isOverridable(): bool
    {
        return $this->rank() >= self::Operator->rank();
    }
}
