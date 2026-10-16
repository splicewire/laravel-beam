<?php

namespace Splicewire\Beam\Realm;

use Illuminate\Support\Facades\Config;
use Splicewire\Beam\Realm\Contracts\TenancyContext;

/**
 * The core default for {@see TenancyContext}: the answers a host gives when no tenancy package is
 * installed, plus the config-derivable half when one is merely configured (ADR-0239).
 *
 * `isActive()`/`currentTenant()` are hard false/null here — beam core stays tenancy-agnostic and
 * never probes for a `tenant()` helper (reintroducing the `function_exists` guard inside the default
 * would rebuild the disease this port removes). The live-tenant half arrives with beam-tenancy's
 * adapter, which plain-`bind()`s over core's `bindIf` (the supersession lane: installing the tenancy
 * package IS the posture declaration).
 *
 * `centralConnection()` keeps TenancyConnections' exact fallback order and null semantics: stancl's
 * own declaration wins when it names a configured connection; absent or dangling means the host runs
 * no split and null ("the connection you would have used anyway") is the honest answer — including
 * in test harnesses whose single sqlite connection is named `testing`, where a literal fallback
 * would degrade every probe to a self-describing pass.
 */
final class CentralTenancyContext implements TenancyContext
{
    public function isMultiTenant(): bool
    {
        return $this->centralConnection() !== null;
    }

    public function isActive(): bool
    {
        return false;
    }

    public function currentTenant(): ?object
    {
        return null;
    }

    public function currentTenantId(): int|string|null
    {
        return null;
    }

    public function centralConnection(): ?string
    {
        $configured = Config::get('tenancy.database.central_connection');

        if (is_string($configured) && $configured !== '' && Config::get("database.connections.{$configured}") !== null) {
            return $configured;
        }

        return null;
    }
}
