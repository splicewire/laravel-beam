<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\Finding;

/**
 * Advisory, presence-conditional: if the frame editor rung is installed AND its
 * ResourceRegistry port is bound in the container, resolve it and report how many
 * resources it carries. On a headless beam app the editor rung is absent — that is a
 * valid configuration (ADR-0082: frame depends on beam, never the reverse), so this
 * PASSes with a skip note. Never FAILs.
 */
class FrameManifestAudit
{
    /** The check name this audit emits; a docs `<DoctorOutput>` sample is checked against them (docs-walkthrough DOC-11(c)). */
    public const CHECK = 'frame manifest';

    public function run(): Finding
    {
        $check = self::CHECK;
        $registryClass = 'Schemastud\\Frame\\Contracts\\ResourceRegistry';

        if (! class_exists($registryClass) || ! app()->bound($registryClass)) {
            return Finding::inconclusive($check, 'frame not installed — editor rung absent (headless beam is valid).');
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
