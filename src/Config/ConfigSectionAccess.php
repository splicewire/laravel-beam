<?php

namespace Splicewire\Beam\Config;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Data\Config\ConfigSectionData;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The `beam-config` read for a transport that is not a particle mount (ADR-0245 decision 8): the MCP read tool.
 *
 * It asks the SAME gate the REST and Frame reads ask, the resource's declared read gate through
 * {@see ResourceVisibility::readable()} (today `entitlement:os.operate`, read off the declaration rather than
 * restated), and reads through the SAME {@see ConfigSectionReader}, so a row cannot differ by transport and a
 * caller the API refuses is refused here too.
 *
 * Absence is a return value: null when the host does not register `beam-config` or the actor may not read it.
 */
class ConfigSectionAccess
{
    public const RESOURCE = 'beam-config';

    /**
     * The ability every operator-tier op on `beam-config` asks (config-sections ticket 05): save-draft, discard-draft,
     * publish, restore and versions. Core defines it ({@see \Splicewire\Beam\BeamServiceProvider}) as {@see permits()},
     * which asks the resource's OWN declared policy (today `entitlement:os.operate`, the estate's staff key), so the
     * ops and the resource answer the same actor the same way and a policy change moves both.
     *
     * The ops do not name `entitlement:os.operate` themselves: core references that key but never defines it (a host
     * does), so an op declaring it would make the core-only `UndefinedEntitlementKeyAudit` report core's own ops. Spelled
     * like {@see \Splicewire\Beam\Particle\Backing\ActorOwnedParticleAccess::WRITE}, core's precedent. The ops name
     * `abilityModel: ConfigOverrideScope::class` (the row they write): a subject-free op is always asked as
     * `entitlement:{ability}` ({@see \Splicewire\Beam\Authorization\AbilityResolver::allows()}), which this is not.
     */
    public const OPERATE = 'beam.config-section.operate';

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
