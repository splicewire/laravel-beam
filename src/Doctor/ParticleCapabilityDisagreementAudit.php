<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\Backing\WritesRecords;
use Splicewire\Beam\Particle\ResourceRegistryReport;
use Splicewire\Beam\Particle\ResourceRegistryRow;

/**
 * Every registered particle resource whose declared INTENT exceeds what its backing can actually do —
 * the on-demand column of `splicewire:beam:particle:resources` promoted to a standing check, so the
 * reading arrives in `surgeon:audit` instead of only when someone remembers to look.
 *
 * ## What it can and cannot see
 *
 * The write axis (`creatable`/`editable`/`deletable` against {@see WritesRecords})
 * is already refused at registration by
 * {@see BackingResolver::assertAffordancesWithinCapability()}, so its
 * normal reading here is zero and a non-zero one means the keyspace was populated around this
 * registry's own `register()`. The READ axis is the live population: nothing validates `filterable`
 * against {@see QueriesRecords}, or `showable` against a backing that
 * can resolve one record, and both default to true — so a custom `ResourceBacking` acquires those two
 * claims by saying nothing.
 *
 * ## Advisory, permanently
 *
 * Which resources are registered is a fact about the HOST — a package declares, a host composes — and
 * the estate's rule is that such a check reports rather than throws (`EventCatalogPrefixAudit` is the
 * instance that took `~/Herd/tower` off the air by getting this backwards). More specifically: a
 * disagreement is a real defect but not necessarily an outage, because the affordance may simply never
 * be exercised on this host, and refusing to boot over a claim nobody calls would trade a work-list line
 * for a dead deployment.
 */
class ParticleCapabilityDisagreementAudit implements DoctorAudit
{
    public const CHECK = 'particle.capability-disagreement';

    public function __construct(private ResourceRegistryReport $report) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $rows = $this->report->rows();

        if ($rows === []) {
            return [Finding::pass(self::CHECK, 'No particle resource is registered on this host — nothing to measure intent against.')];
        }

        $disagreeing = array_values(array_filter(
            $rows,
            static fn (ResourceRegistryRow $row): bool => $row->disagreements !== [],
        ));

        if ($disagreeing === []) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d registered particle resource%s; every declared affordance stays within what its backing implements.',
                count($rows),
                count($rows) === 1 ? '' : 's',
            ))];
        }

        return [Finding::warn(self::CHECK, sprintf(
            '%d of %d registered particle resource%s declare more than their backing can do: %s. '
                .'Capability is the ceiling — narrow the declaration, or give the backing the capability it '
                .'is being credited with. Run `splicewire:beam:particle:resources --disagreements` for the full row.',
            count($disagreeing),
            count($rows),
            count($rows) === 1 ? '' : 's',
            implode('; ', array_map(
                static fn (ResourceRegistryRow $row): string => sprintf(
                    '[%s] %s (backing %s)',
                    $row->key,
                    implode(', ', $row->disagreements),
                    class_basename($row->backing),
                ),
                $disagreeing,
            )),
        ))];
    }
}
