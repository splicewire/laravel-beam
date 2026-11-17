<?php

namespace Splicewire\Beam\Config;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Data\Config\ConfigSectionData;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\Contracts\TenancyContext;
use Throwable;

/**
 * The `beam-config` read for a transport that is not a particle mount (ADR-0245 decision 8): the MCP read tool.
 *
 * It asks the SAME gate the REST and Frame reads ask, the resource's declared read gate through
 * {@see ResourceVisibility::readable()} ({@see READ}, read off the declaration rather than restated), and reads through
 * the SAME {@see ConfigSectionReader}, so a row cannot differ by transport and a caller the API refuses is refused here
 * too.
 *
 * Absence is a return value: null when the host does not register `beam-config` or the actor may not read it.
 */
class ConfigSectionAccess
{
    public const RESOURCE = 'beam-config';

    /**
     * `beam-config`'s declared read policy (config-sections ticket 07), which core defines ({@see allowsRead()}): outside a
     * tenant it is the operator's existing staff key, `entitlement:os.operate`, exactly as 02 and 05 declared it; inside
     * a tenant it is the host's `beam.core.config_overrides.tenant_ability`, and with none configured no tenant user
     * reads or writes a workspace's config. One declared ability, so every transport (REST, Frame, MCP, the ops) asks the
     * same question and the realm decides the answer; the staff key never opens a tenant's tier, and a tenant ability
     * never opens the operator's.
     */
    public const READ = 'beam.config-section.read';

    /**
     * The ability every op on `beam-config` asks (config-sections tickets 05, 07): save-draft, discard-draft, publish,
     * restore, versions and lock. Core defines it ({@see \Splicewire\Beam\BeamServiceProvider}) as {@see permits()},
     * which asks the resource's OWN declared policy ({@see READ}), so the ops and the resource answer the same actor the
     * same way, in each realm, and a policy change moves both.
     *
     * The ops do not name `entitlement:os.operate` themselves: core references that key but never defines it (a host
     * does), so an op declaring it would make the core-only `UndefinedEntitlementKeyAudit` report core's own ops. Spelled
     * like {@see \Splicewire\Beam\Particle\Backing\ActorOwnedParticleAccess::WRITE}, core's precedent. The ops name
     * `abilityModel: ConfigOverrideScope::class` (the row they write): a subject-free op is always asked as
     * `entitlement:{ability}` ({@see \Splicewire\Beam\Authorization\AbilityResolver::allows()}), which this is not.
     */
    public const OPERATE = 'beam.config-section.operate';

    /** The staff key the operator realm reads `beam-config` with (02, 05). */
    public const OPERATOR_ABILITY = 'entitlement:os.operate';

    public function __construct(
        protected ParticleResourceRegistry $resources,
        protected ResourceVisibility $visibility,
        protected ConfigSectionRegistry $sections,
        protected ConfigSectionReader $reader,
    ) {}

    /**
     * {@see OPERATE}: a signed-in actor the resource's declared policy allows. A guest, an unregistered `beam-config`, or
     * a resource with no policy string is refused: these are write and history ops, so there is no Public Read arm.
     * A host that never defines the policy's key gets the answer that key gets everywhere (Exec ADR-0001: an undefined
     * key denies everyone), the same as the resource's own read.
     *
     * Static, and reading only the resource registry and the Gate: building this class would build the section reader
     * and with it the Schemastud `Generator` chain, which a boot that registers only some providers cannot resolve.
     */
    public static function permits(?Authenticatable $actor): bool
    {
        $policy = app(ParticleResourceRegistry::class)->find(self::RESOURCE)?->policy;

        if ($actor === null || ! is_string($policy) || $policy === '') {
            return false;
        }

        return app(Gate::class)->forUser($actor)->allows($policy);
    }

    /**
     * {@see READ}: the staff key outside a tenant; the host's configured tenant ability inside one, or nobody when the
     * host names none. A guest is refused.
     */
    public static function allowsRead(?Authenticatable $actor): bool
    {
        if ($actor === null) {
            return false;
        }

        $ability = self::inTenant() ? config('beam.core.config_overrides.tenant_ability') : self::OPERATOR_ABILITY;

        return is_string($ability) && $ability !== '' && app(Gate::class)->forUser($actor)->allows($ability);
    }

    /** Whether this call runs inside a tenant (the tenant realm's rows and ops). */
    public static function inTenant(): bool
    {
        try {
            return app(TenancyContext::class)->isActive();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Every section's row ordered by root, or the one row for `$root` (an empty list when no section has it).
     *
     * @return list<ConfigSectionData>|null null when the actor may not read `beam-config`
     */
    public function rows(?Authenticatable $actor, ?string $root = null): ?array
    {
        $resource = $this->resources->find(self::RESOURCE);

        if ($resource === null || ! $this->visibility->readable($resource, $actor)) {
            return null;
        }

        if ($root !== null) {
            $section = $this->sections->get($root);

            return $section === null ? [] : [$this->reader->read($section)];
        }

        return array_map(
            fn (RegisteredConfigSection $section): ConfigSectionData => $this->reader->read($section),
            $this->sections->all(),
        );
    }
}
