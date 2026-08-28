<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Schemastud\Frame\Contracts\FrameResourceHandlerResolver;
use Splicewire\Beam\Doctor\ParticleCapabilityDisagreementAudit;
use Splicewire\Beam\Particle\Backing\BackingResolver;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\ResourceRegistryReport;
use Splicewire\Beam\Particle\ResourceRegistryRow;

/**
 * Enumerate this host's whole particle-resource vocabulary — every registered declaration, what its
 * backing can actually do, what the declaration claims, and where the two disagree.
 *
 * ## Why a command and not an operator screen
 *
 * The proposal this answers asked for a resource-registry AREA. Two measurements moved it to a command.
 *
 * **Nav-invisibility is the default, not a backlog.** Only ~29 of ~165 `#[ParticleResource]`
 * declarations in the estate name a nav `section`, and a resource without one matches no rail. So "the
 * registry is unreachable from nav" is not a gap a screen closes; it is the mechanism, and what was
 * missing is any way to READ the set.
 *
 * **A screen over that set cannot be built safely at that tier.** The host's nav gate
 * (`FrameResourcesInvocable::resourceViewable()`) is what its own docblock calls *secure-by-omission* —
 * a model-less resource skips `viewAny` entirely — so a surface enumerating every key either
 * reimplements the gate or discloses the schema surface of resources the viewer is denied. That is this
 * estate's recurring defect class (a check that reports success by not running) placed on the one
 * surface designed to show everything. A command answers the identical question, read-only, for an
 * operator who already holds a shell on the host.
 *
 * ## The column that earns it
 *
 * `disagree` is intent measured against capability: a declaration opening an affordance its backing
 * cannot honour. Finding one used to be a two-file read — the declaration, then whatever its `backing:`
 * slot names — per resource. Capabilities are read by `instanceof` through
 * {@see BackingResolver::hasCapability()}, never from a declared flag,
 * so the column cannot drift from the thing it describes.
 *
 * `handler` is the second: it surfaces in-product what `api-surface-coherence` ticket 112 measured by
 * hand — how much of the registry falls through to a host's default handler, which honours none of a
 * declaration's conventions.
 *
 * Both are HOST facts, which is why nothing here throws: what a host registered and what handler it
 * bound are not things a declaration's author could have gotten right (AGENTS.md — a check whose answer
 * depends on the host must not throw). The same rows are also reported by
 * {@see ParticleCapabilityDisagreementAudit}, advisory, so they surface in
 * `surgeon:audit` without anyone having to remember this command exists.
 */
class ParticleResourcesCommand extends Command
{
    protected $signature = 'splicewire:beam:particle:resources
        {--realm= : Only resources whose membership includes this realm}
        {--section= : Only resources in this nav section; pass `none` for the ones that opt out of nav}
        {--disagreements : Only rows whose declared intent exceeds what their backing can do}
        {--json : Emit the full rows as JSON instead of the table}';

    protected $description = 'List every registered particle resource with its backing capabilities, its declared intent, and where the two disagree.';

    public function handle(ParticleResourceRegistry $registry, FrameResourceHandlerResolver $handlers): int
    {
        $report = new ResourceRegistryReport($registry, $handlers);

        $all = $report->rows();
        $rows = $report->filtered(
            realm: $this->stringOption('realm'),
            section: $this->stringOption('section'),
            disagreementsOnly: (bool) $this->option('disagreements'),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_map(fn (ResourceRegistryRow $row) => $row->toArray(), $rows),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->components->warn($all === []
                ? 'No particle resource is registered on this host.'
                : sprintf('No resource matches that filter (%d registered).', count($all)));

            return self::SUCCESS;
        }

        $this->table(
            ['key', 'label', 'realms', 'section', 'capability', 'intent', 'policy', 'model', 'handler', 'disagree'],
            array_map(fn (ResourceRegistryRow $row) => $this->cells($row), $rows),
        );

        $this->summarise($report, $all, $rows);

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function cells(ResourceRegistryRow $row): array
    {
        return [
            $row->key,
            $row->label === '' ? '—' : $row->label,
            $row->realms === [] ? '—' : implode(',', $row->realms),
            // The one cell whose EMPTY state is the interesting one — an em dash rather than a blank so
            // it reads as a declared absence instead of a rendering gap.
            $row->section ?? '—',
            $row->capabilities(),
            $row->intent(),
            $row->policy ?? '—',
            $row->model === null ? '—' : class_basename($row->model),
            $row->handler === null ? '—' : class_basename($row->handler),
            $row->disagreements === [] ? '' : implode('; ', $row->disagreements),
        ];
    }

    /**
     * The four numbers the report exists to produce, always over the WHOLE registry rather than the
     * filtered view — a census narrowed by a filter is a different claim, and printing it under the same
     * heading is how a partial reading gets quoted forward as an estate figure.
     *
     * @param  list<ResourceRegistryRow>  $all
     * @param  list<ResourceRegistryRow>  $shown
     */
    private function summarise(ResourceRegistryReport $report, array $all, array $shown): void
    {
        $navigable = array_filter($all, fn (ResourceRegistryRow $row) => $row->section !== null);
        $disagreeing = array_filter($all, fn (ResourceRegistryRow $row) => $row->disagreements !== []);
        $generic = array_filter($all, fn (ResourceRegistryRow $row) => $this->isGenericHandler($row));

        $this->newLine();
        $this->line(sprintf(
            '  <options=bold>%d</> registered · <options=bold>%d</> opt into nav (%d in no section) · '
                .'<options=bold>%d</> with an intent/capability disagreement · <options=bold>%d</> on a generic handler',
            count($all),
            count($navigable),
            count($all) - count($navigable),
            count($disagreeing),
            count($generic),
        ));

        $this->line(sprintf(
            '  handlers resolved through <options=bold>%s</>.',
            $report->resolvedBy() === null ? 'nothing (no resolver bound)' : class_basename($report->resolvedBy()),
        ));

        if (count($shown) !== count($all)) {
            $this->line(sprintf('  (%d row(s) shown by the active filter.)', count($shown)));
        }
    }

    /**
     * Whether nothing bespoke serves this key.
     *
     * Matched on the class NAME rather than on a class-string, because one of the two generic handlers is
     * HOST-owned and beam cannot name it: at `~/Herd/splicewire-app` the fall-through is
     * `App\Frame\DefaultResourceHandler`, which `api-surface-coherence` 112 measured as honouring none of
     * a declaration's conventions; beam's own OOTB resolver answers `ParticleFrameResourceHandler`, which
     * honours all of them. They are opposite in quality and identical in the fact this counts — no
     * per-resource handler was written — so the resolver line above is what tells the two apart.
     */
    private function isGenericHandler(ResourceRegistryRow $row): bool
    {
        if ($row->handler === null) {
            return false;
        }

        $name = class_basename($row->handler);

        return $name === 'DefaultResourceHandler' || $name === 'ParticleFrameResourceHandler';
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
