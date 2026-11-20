<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Agreements\Http\RouteRequirementGate;
use Splicewire\Beam\Agreements\RequirementResolver;
use Splicewire\Beam\Agreements\Requires;
use Splicewire\Beam\Agreements\SlotRequirementDeclarations;
use Splicewire\Beam\Agreements\SlotRequirementResolver;

/**
 * **A `requires:` slot with no agreements seam installed refuses every caller** (agreements 05, ADR-0247 amendment).
 *
 * Beam's default {@see SlotRequirementResolver} cannot know any Agreement, so it reports each slot Requirement as
 * outstanding with `kind: 'unregistered'`: every caller of that behavior gets a 428 it has no way to satisfy. That is
 * the safe reading of a declaration nobody can honour, but it is never what a host meant, so this audit names each
 * behavior and key while the bound resolver is still beam's slot default. It passes once an agreements adapter binds
 * its own resolver, and passes trivially when no slot declares anything.
 *
 * Registered `gate: true`: the finding has one reading — a route that refuses everyone — and the remedy is a host fact
 * (install the seam or remove the slot), never a judgement the warn floor should absorb.
 *
 * ⚠️ It reads the BOOTED registries through {@see SlotRequirementDeclarations}, like the sibling requirement audit.
 */
class UnseamedRequirementSlotAudit implements DoctorAudit
{
    public const CHECK = 'requirements.unseamed-slot';

    public function __construct(
        protected SlotRequirementDeclarations $slots,
        protected RequirementResolver $resolver,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        // agreements 07: a route's Requirement (`route:{name}`) PASSES while no seam is installed (ADR-0247 amendment 07),
        // so it is never refused as unknown and isn't this check's business.
        $declared = array_filter(
            $this->slots->all(),
            fn (string $behavior): bool => ! str_starts_with($behavior, RouteRequirementGate::BEHAVIOR_PREFIX),
            ARRAY_FILTER_USE_KEY,
        );

        if ($declared === []) {
            return [Finding::pass(self::CHECK, 'No operation or resource declares a `requires:` slot.')];
        }

        if (! $this->resolver instanceof SlotRequirementResolver) {
            return [Finding::pass(self::CHECK, sprintf(
                '%d behavior%s carr%s a `requires:` slot, answered by the installed agreements resolver [%s].',
                count($declared),
                count($declared) === 1 ? '' : 's',
                count($declared) === 1 ? 'ies' : 'y',
                $this->resolver::class,
            ))];
        }

        $lines = [];
        foreach ($declared as $behavior => $requirements) {
            $lines[] = sprintf('  %-44s requires %s', $behavior, implode(', ', array_map(
                fn (Requires $requirement): string => $requirement->agreement,
                $requirements,
            )));
        }

        return [Finding::fail(self::CHECK, sprintf(
            '%d behavior%s declare%s a `requires:` slot but no agreements seam is installed, so every caller is refused '
            ."(428) as an unknown Agreement until an agreements adapter is installed:\n%s\n\nInstall an agreements "
            .'adapter that binds a RequirementResolver, or remove the slot.',
            count($declared),
            count($declared) === 1 ? '' : 's',
            count($declared) === 1 ? 's' : '',
            implode("\n", $lines),
        ))];
    }
}
