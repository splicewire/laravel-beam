<?php

namespace Splicewire\Beam\Source;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnDuplicate;
use Rushing\Popcorn\Registries\RegistryArity;

/**
 * The class `config('beam.client.sources')` never had.
 *
 * {@see RouteManifestSource} is an **interface**, and its registry is the objectless config array a host
 * binds one source per realm into — `defaults` for the tenant tier, `operator` for the operator one.
 * There is no class that owns the keyspace, which is why registry-kernel ticket 21 could declare
 * `#[IsRegistry]` on eighteen of beam-core's nineteen registries and left this one `deferred` against the
 * ticket that landed {@see ConfigRegistry}. This is that one, closing the deferral.
 *
 * Nothing about the wiring changes. `config/beam/client.php` still spells the two realms, the env vars
 * still override them, and `GenerateClientSdkCommand`, `SdkReturnsCoverageAudit` and
 * `SdkReturnsTypeScriptResolutionAudit` still read the config path directly. What this adds is the
 * declaration those three could never carry: the registry is now visible to the index, to
 * `popcorn:registries`, and to the conformance gate.
 *
 * ## `PickOne`, and entries are class-strings
 *
 * A read asks for one realm's source and gets one binding. The entries are class-strings resolved
 * through the container by the consumer — the config array is the storage, so the registry hands back
 * exactly what the host wrote there rather than quietly making it.
 *
 * An unbound realm is `null` (`env('BEAM_CLIENT_OPERATOR_SOURCE')` with nothing set), and
 * {@see ConfigRegistry} skips nulls, so `has('operator')` is false on a host that never bound one —
 * which is what `GenerateClientSdkCommand` already means when it treats a non-string binding as an
 * absent operator tier and emits no operator hooks.
 */
#[IsRegistry(
    root: 'beam.client.sources',
    of: 'RouteManifestSource bindings by realm — the per-tier route manifests the client SDK codegen generates from',
    arity: RegistryArity::PickOne,
    entryType: 'class-string<'.RouteManifestSource::class.'>',
    onDuplicate: OnDuplicate::Supersede,
    note: 'Storage is `config(\'beam.client.sources\')`, a realm-keyed map bound per host (env-overridable). An unbound realm is null and reads as absent, matching GenerateClientSdkCommand\'s own reading.',
)]
class RouteManifestSourceRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'beam.client.sources';
    }
}
