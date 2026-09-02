<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surface\GroupRegistry;

/**
 * A `#[ParticleResource(group: '…')]` word that resolves to no group on this host (api-surface-coherence
 * ticket 143).
 *
 * Rung 2 of {@see GroupRegistry::resolveRoute()} resolves a LOOSE word a package declares — a key, the
 * canonical name, a `navLabel`, or a slug of any — against the host's group tree. That looseness is
 * deliberate: it lets `splicewire/tower` say *"I belong to Compliance"* without depending on the host's
 * key space. Its cost is that the binding is by string, unversioned, and across a repo boundary, so a host
 * can rename its half (`c077ea244`: `compliance` → `determination`) and nothing anywhere fails. Six
 * tower declarations kept spelling the old word for a day; twelve routes fell to the URI guess; the only
 * instrument that noticed lumped them into a drift count.
 *
 * ## Why an advisory, and why it reads the WORD rather than the placement
 *
 * Whether a word is orphaned is a fact about the HOST — the taxonomy is host-owned (beam-core ships no
 * groups), and `UserData` → `Settings`, `TeamData` → `Platform`, `BeamUxEntryData` → `Content` ship
 * inside packages as defaults a host may never adopt. `GroupRegistry::resolve()` already answers a miss
 * with `null` rather than a throw for exactly that reason, so this is a work-list, never a Fail.
 *
 * It resolves the DECLARED word, not `forResource()`. A host can place an orphaned resource at rung 1
 * (`assign()`), which fixes the routes and leaves the class shipping a word that resolves nowhere — the
 * next host to install the package inherits the miss. Ticket 143 requires that a rung-1 placement must
 * not hide a rung-2 break, so a placed orphan is still reported, and the row says it is placed.
 *
 * ## What it cannot see
 *
 * Only what is registered in the running host. With no resources or no groups registered there is no
 * population, and the finding says so as inconclusive rather than as a clean pass.
 */
class OrphanedGroupWordAudit implements DoctorAudit
{
    public const CHECK = 'surface.orphaned-group-word';

    public function __construct(
        private GroupRegistry $groups,
        private ParticleResourceRegistry $resources,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $declared = array_values(array_filter(
            $this->resources->all(),
            static fn (ParticleResource $resource): bool => is_string($resource->group) && $resource->group !== '',
        ));

        if ($declared === []) {
            return [Finding::inconclusive(self::CHECK, 'No particle resource on this host declares a group — nothing for a group word to miss.')];
        }

        if ($this->groups->all() === []) {
            return [Finding::inconclusive(self::CHECK, sprintf(
                'No API groups are registered on this host, so none of the %d declared group word%s can resolve; a host with no taxonomy has nothing to rename.',
                count($declared),
                count($declared) === 1 ? '' : 's',
            ))];
        }

        $findings = [];

        foreach ($declared as $resource) {
            if ($this->groups->resolve($resource->group) !== null) {
                continue;
            }

            $placed = $this->groups->forResource($resource->key);

            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%s (resource [%s]) declares group [%s], which resolves to no group registered on this host — not as a key, a name, a navLabel, or a slug of any. %s',
                $resource->data ?? '(no Data class)',
                $resource->key,
                $resource->group,
                $placed === null
                    ? 'Its routes fall through to the URI guess. Spell the word this host\'s taxonomy uses, or place the resource with a host assignment.'
                    : sprintf(
                        'A host assignment places it under [%s], so its routes group correctly — but the class still ships a word that resolves nowhere, and the next host inherits the miss (api-surface-coherence 143: a rung-1 placement must not hide a rung-2 break).',
                        $placed->key,
                    ),
            ));
        }

        if ($findings === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d particle resource%s declare%s a group; every word resolves to a registered group on this host.',
                count($declared),
                count($declared) === 1 ? '' : 's',
                count($declared) === 1 ? 's' : '',
            ))];
        }

        return $findings;
    }
}
