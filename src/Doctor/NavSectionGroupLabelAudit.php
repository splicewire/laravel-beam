<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;

/**
 * Names every seat whose groups repeat a label (analytics-area 22, owner ruling 2026-11-12: "a duplicate label is a doctor
 * finding, not a silent override").
 *
 * Same-key seats merge their groups, the host's first ({@see NavSectionRegistry::for()}), and nothing is dropped. So when a
 * host and a package (or two packages) both name a group "Traffic", the strip would draw two tabs with one word. That is a
 * warning, not a failure: each group still folds its own children, and which word to change is the declarers' call.
 * It reads the merged seats of every realm a seat targets, the same set a projector draws.
 */
class NavSectionGroupLabelAudit implements DoctorAudit
{
    public const CHECK = 'nav.section-group-labels';

    public function __construct(private readonly NavSectionRegistry $sections) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $findings = [];
        foreach ($this->sections->all() as $seat) {
            foreach ($this->duplicates($seat) as $label => $declarers) {
                $findings[] = Finding::warn(self::CHECK, sprintf(
                    'Nav seat [%s.%s] has %d groups labelled "%s" (declared by %s). Give each group its own label.',
                    $seat->realm,
                    $seat->key,
                    count($declarers),
                    $label,
                    implode(', ', array_map(static fn (?string $by): string => $by ?? 'the host', $declarers)),
                ));
            }
        }

        return $findings !== [] ? $findings : [Finding::pass(self::CHECK, 'No nav seat repeats a group label.')];
    }

    /** @return array<string, list<?string>> label => the registrant of each group that carries it, when more than one */
    private function duplicates(NavSection $seat): array
    {
        $byLabel = [];
        foreach ($seat->groups as $i => $group) {
            $byLabel[$group['label']][] = $seat->groupRegistrant($i);
        }

        return array_filter($byLabel, static fn (array $declarers): bool => count($declarers) > 1);
    }
}
