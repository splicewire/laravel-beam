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
 * ## A command AND an operator screen — and why each exists
 *
 * The proposal this answers asked for a resource-registry AREA. Both now exist, over the one
 * {@see ResourceRegistryReport}, and they answer the question for two different readers.
 *
 * **Nav-invisibility is the default, not a backlog.** A resource that names no nav `section` matches no
 * rail, and roughly half the registry names none. So "the registry is unreachable from nav" is not a gap
 * a nav change closes; it is the mechanism, and what was missing was any way to READ the set.
 *
 * **This command is the shell reader: whole, unfiltered, ungated.** An operator who already holds a shell
 * on the host holds more than any listing could disclose, so the command reports every declaration —
 * including the ones a signed-in viewer is denied — which is what a doctor sweep, a census and a
 * disagreement hunt need. Command-line invocation is ungated by policy (particle doctrine, "Authorization
 * is per transport").
 *
 * **The operator Resources area is the in-product reader, and it is gated per ROW.** A screen enumerating
 * every key must not become the bypass around the gate that hides a resource from the rail, and that was
 * what kept it a command: the nav gate used to let a model-less resource skip `viewAny` entirely. Beam's
 * `Splicewire\Beam\Authorization\ResourceVisibility::listable()` is now the one answer the nav collectors
 * and the frame sockets share, so `Splicewire\Beam\Particle\Registry\ResourceRegistryBacking` filters
 * every row through it and a viewer sees only resources it may list — absent, not disabled
 * (registry-kernel ticket 17 D5). The screen is for opening a resource's surfaces; this command is for
 * seeing all of them at once.
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
     * Matched on the class NAME rather than on a class-string, because a generic fall-through handler may
     * be HOST-owned, in which case beam cannot name it. The flagship's was `App\Frame\DefaultResourceHandler`
     * — which `api-surface-coherence` 112 measured as honouring none of a declaration's conventions, against
     * beam's `ParticleFrameResourceHandler`, which honours all of them. Opposite in quality, identical in
     * the fact this counts (no per-resource handler was written), which is why the resolver line above is
     * what tells them apart.
     *
     * That particular host handler is gone: the flagship's `key => handler` table dissolved onto the
     * declaration's `handler:` slot, and the nine resources that had been silently falling through to it
     * now ride the generic handler. The name-matching stays, because a host may still bind its own
     * resolver and its own fall-through — `numero` and `schemastud` do.
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
