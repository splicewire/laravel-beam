<?php

namespace Splicewire\Beam\Doctor;

use Schemastud\Doctor\Finding;

/**
 * Advisory, presence-conditional: if the frame editor rung is installed AND its
 * AdminResourceRegistry is bound in the container, resolve it and report how many
 * resources it carries. On a headless beam app the editor rung is absent — that is a
 * valid configuration (ADR-0082: frame depends on beam, never the reverse), so this
 * PASSes with a skip note. Never FAILs.
 */
class FrameManifestAudit
{
    public function run(): Finding
    {
        $check = 'frame manifest';
        $registryClass = 'Schemastud\\Frame\\Registry\\AdminResourceRegistry';

        if (! class_exists($registryClass) || ! app()->bound($registryClass)) {
            return Finding::pass($check, 'frame not installed — editor rung absent (headless beam is valid).');
        }

        try {
            $registry = app($registryClass);
            $count = is_object($registry) && method_exists($registry, 'all')
                ? count($registry->all())
                : 0;

            return Finding::pass($check, 'frame manifest resolves ('.$count.' resource'.($count === 1 ? '' : 's').').');
        } catch (\Throwable $e) {
            return Finding::warn($check, 'frame installed but its resource registry failed to resolve: '.$e->getMessage().'.');
        }
    }
}
