<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;

/** Enumerates every resource that explicitly opts its read population into the global boundary. */
class GlobalReadBoundaryAudit implements DoctorAudit
{
    public const CHECK = 'resource.read.global-boundary';

    public function __construct(private ParticleResourceRegistry $resources) {}

    public static function forApp(): self
    {
        return new self(app(ParticleResourceRegistry::class));
    }

    /** @return list<string> */
    public function keys(): array
    {
        $keys = [];
        foreach ($this->resources->all() as $resource) {
            if ($resource->readBoundary === ParticleResource::READ_BOUNDARY_GLOBAL) {
                $keys[] = $resource->key;
            }
        }
        sort($keys);

        return $keys;
    }

    /** @return list<Finding> */
    public function run(): array
    {
        $keys = $this->keys();

        return [Finding::pass(
            self::CHECK,
            $keys === []
                ? 'No particle resource declares the global read boundary.'
                : 'Declared-global particle resources: '.implode(', ', $keys).'.',
        )];
    }
}
