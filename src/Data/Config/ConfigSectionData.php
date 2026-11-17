<?php

namespace Splicewire\Beam\Data\Config;

use Schemastud\Frame\Attributes\Column;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Config\ConfigSectionAccess;
use Splicewire\Beam\Config\ConfigSectionBacking;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Particle\Attributes\ParticleResource;

/**
 * The READ projection for the `beam-config` particle resource, and its declaration site (ADR-0245). Scaffolded by
 * `splicewire:beam:make:particle-resource ConfigSection --key=beam-config --read-only`.
 *
 * One row per registered Config Section: its identity, its JSON Schema, its effective values and each leaf key's
 * provenance (tier, env binding, declaration). The schema is served beside the values, the ADR-0235 pattern: a
 * row's value members are typed by its own section's schema, not by a type this class could generate.
 *
 * Read-only by construction for now: every key a section declares is deploy-only until the override store exists,
 * so `input: false` and the backing declines `WritesRecords`. Drafts and publishing arrive as ops.
 *
 * Realm membership is the host's (`frame.realms`): the operator realm reads the central tier, and the tenant realm
 * (while a tenant is initialized) the tenant tier (ticket 07). The read gate is core's {@see ConfigSectionAccess::READ}:
 * outside a tenant the operator's existing ability, `entitlement:os.operate` (the estate's one staff key, asked
 * subject-free; {@see \Splicewire\Beam\Realm\RealmEntitlementResourceGate::CentralRealmEntitlement}), because a
 * section's rows name env vars and install paths; inside a tenant the ability the host names in
 * `beam.core.config_overrides.tenant_ability`, and nobody when it names none.
 *
 * The attribute DECLARES; the host ROUTES:
 *
 *     Particle::mount('beam-config', 'beam-config');
 */
#[TypeScript]
#[ParticleResource(
    key: 'beam-config',
    backing: ConfigSectionBacking::class,
    input: false,
    perPage: 100,
    label: 'Config',
    // config-sections 07: core's realm-aware read ability (the staff key outside a tenant, the host's tenant ability
    // inside one; {@see ConfigSectionAccess::READ}).
    policy: ConfigSectionAccess::READ,
    readOnly: true,
    singularLabel: 'Config section',
    // ADR-0230 Public Read: none. A section names env vars and install paths, so no guest reads it.
    publicRead: null,
)]
class ConfigSectionData extends BeamData
{
    /**
     * @param  array<string, mixed>  $schema  the section's JSON Schema
     * @param  array<array-key, mixed>  $values  the effective values, secrets redacted to null
     * @param  list<ConfigKeyData>  $keys
     * @param  list<string>  $shadowedPackageKeys  package leaves the host's top-level copy hides
     * @param  list<ConfigOverrideData>  $overrides  the row tier's sparse draft: the operator's (ticket 05), or in the
     *                                               tenant realm the current tenant's (ticket 07)
     * @param  ConfigTier  $tier  the tier this row's draft, pins and ops act on: `operator` in the operator realm, `tenant`
     *                            while a tenant is initialized (ticket 07). In the tenant realm the row lists only keys
     *                            whose declared ceiling admits the tenant tier.
     */
    public function __construct(
        public string $id,
        #[Column(label: 'Root', sort: 0)]
        public string $root,
        #[Column(label: 'Section', sort: 1)]
        public string $title,
        public string $description,
        #[Column(label: 'Owner', sort: 2)]
        public string $owner,
        public ConfigSectionFilesData $files,
        public array $schema,
        public array $values,
        #[DataCollectionOf(ConfigKeyData::class)]
        public array $keys,
        public array $shadowedPackageKeys,
        public ConfigSectionCountsData $counts,
        #[DataCollectionOf(ConfigOverrideData::class)]
        public array $overrides = [],
        public ConfigPinsData $pins = new ConfigPinsData(draft: null, published: null, draftPending: false),
        /** What a publish must tell the operator, e.g. a failed cache write's propagation bound (root ruling (A), row 64e24681). */
        public ?string $notice = null,
        public ConfigTier $tier = ConfigTier::Operator,
    ) {}
}
