<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Nav\NavSection;

/**
 * Names every class that declared a {@see NavSection} without an `audience` (lead 17:34Z: deprecate-with-default).
 *
 * Such a seat still works: it is drawn as a product seat. It is a warning, not a failure, so an installed host that
 * predates UX-08a keeps booting and is told what to change. It reads the declarers noted while this process booted, so
 * it sees every seat the host registered.
 */
class NavSectionAudienceAudit implements DoctorAudit
{
    public const CHECK = 'nav.section-audience';

    /** @return list<Finding> */
    public function run(): array
    {
        $declarers = NavSection::undeclaredAudience();

        if ($declarers === []) {
            return [Finding::pass(self::CHECK, 'Every NavSection declares its audience.')];
        }

        return array_map(fn (string $declarer): Finding => Finding::warn(self::CHECK, sprintf(
            '%s declares a NavSection without `audience:`; it is drawn as a product seat. Pass it explicitly (ux-walkthrough UX-08); it is required again at the next major.',
            $declarer,
        )), $declarers);
    }
}
