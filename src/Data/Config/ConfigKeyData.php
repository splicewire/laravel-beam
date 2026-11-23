<?php

namespace Splicewire\Beam\Data\Config;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigMerge;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * One leaf key of a Config Section with its provenance (ADR-0245).
 *
 * - `pointer`: an RFC 6901 pointer relative to the section root. Keys contain dots, so pointers, not dot paths.
 * - `value`: the effective value, as `config()` holds it. Null for a secret, whose `set` says whether it has one.
 * - `tier`: the tier that set it. `hostShadowsPackage` marks a host value that replaced the package's whole
 *   top-level key (`mergeConfigFrom()` is shallow).
 * - `env`: the env var feeding the winning tier's value, where there is one.
 * - `effect`, `ceiling`, `overridable`, `secret`, `merge`: the key's declaration, defaulted conservatively when
 *   the section declares none. `overridable` is for the row's own tier: in the tenant realm, false for a locked key.
 * - `lock`: the operator lock covering the key, with its reason (ticket 07), or null.
 * - `closed`: the runtime tiers the package closed the key to (`#[ConfigKey(closed: ...)]`, config-sections 17). The
 *   Config area shows such a tier's layer as closed, not as "not set"; a stored row for it never applies.
 */
#[TypeScript]
class ConfigKeyData extends BeamData
{
    /**
     * @param  list<ConfigLayerData>  $layers
     * @param  list<ConfigTier>  $closed  serialized as the tiers' values (`["operator"]`)
     */
    public function __construct(
        public string $pointer,
        public mixed $value,
        public ConfigTier $tier,
        public bool $hostShadowsPackage,
        #[DataCollectionOf(ConfigLayerData::class)]
        public array $layers,
        public ?ConfigEnvData $env,
        public ConfigEffect $effect,
        public ConfigTier $ceiling,
        public bool $overridable,
        public bool $secret,
        public bool $set,
        public ConfigMerge $merge,
        public ?ConfigLockData $lock = null,
        public array $closed = [],
    ) {}
}
