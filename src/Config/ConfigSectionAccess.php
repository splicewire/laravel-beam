<?php

namespace Splicewire\Beam\Config;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Authorization\ResourceVisibility;
use Splicewire\Beam\Data\Config\ConfigSectionData;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/**
 * The `beam-config` read for a transport that is not a particle mount (ADR-0242 decision 8): the MCP read tool.
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

    public function __construct(
        protected ParticleResourceRegistry $resources,
        protected ResourceVisibility $visibility,
        protected ConfigSectionRegistry $sections,
        protected ConfigSectionReader $reader,
    ) {}

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
