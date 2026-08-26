<?php

namespace Splicewire\Beam\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Doctor\RegistryConformanceAudit;
use Splicewire\Beam\Doctor\UndeclaredRegistryShapeAudit;

/**
 * Writes and checks the **committed** registry-conformance artifact — the ratchet behind registry-kernel's
 * completeness claim, and the reporting surface for its two audits.
 *
 * Mirrors {@see UndeclaredSurfaceCommand}, which is the estate's working template for this shape (14 D8,
 * D12): a committed artifact, a `--write` that rewrites it, a `--check` that fails only on an INCREASE, and
 * sorted rows so a no-op re-run is byte-identical. A number that lives only in a command's output is a
 * number nobody is accountable to; committed, it appears as a diff in review and can only move one way.
 *
 * ## Two thresholds, and they are not the same threshold
 *
 *   - **`--check` guards the ratchet, forever.** `outstanding` may never rise. This survives the map: long
 *     after registry-kernel closes, a new registry-shaped class appearing undeclared is still a regression.
 *   - **The map closes on a stricter line**: `outstanding` is ZERO *and* `unaccounted` is EMPTY. A green
 *     `--check` is not that. `--check` passing means "no worse than yesterday", which is a different claim
 *     from "every registry in this composition is accounted for", and conflating the two is how a burn-down
 *     gets declared finished at forty rows.
 *
 * ## The limit, stated because the number invites the wrong reading
 *
 * **This artifact is a claim about a COMPOSITION, not about the family** (14 D12). It is written by booting
 * one host and reading what that host composed; a package this host does not install contributes nothing to
 * it, and a package it installs at an older version contributes that version. `splicewire-app` is the
 * composition the map closes against, because it is the flagship that composes the whole family.
 *
 * **Do not build a cross-host aggregator here.** That is the ticket-03 census's job and a boot structurally
 * cannot do it: the census walks 93 packages on disk, most of which no single host installs at once.
 *
 * ## The four-way breakdown, plus the residue
 *
 * `conforming` is the DECLARED population passing {@see RegistryConformanceAudit}; the other three are
 * {@see UndeclaredRegistryShapeAudit}'s dispositions. `unaccounted` is neither — it is the registry-shaped,
 * undeclared, un-dispositioned residue, and it is the set the map's completeness assertion is about. A
 * `--write` empties it by recording each row as `outstanding`, which is the point: you cannot burn down what
 * you have not counted.
 */
class RegistryConformanceCommand extends Command
{
    public const SIGNATURE = 'splicewire:beam:registry-conformance';

    /**
     * **The population this artifact's completeness claim is over — printed, not merely known**
     * (registry-kernel ticket 55, on ticket 35 D2's precedent that *a green gate must print the thing
     * it is not gating on*).
     *
     * Both audits behind this artifact find registries by their **wiring**: `RegistryConformanceAudit`
     * walks the live binding table plus the index's members, and `UndeclaredRegistryShapeAudit` scans
     * provider source for `singleton()`/`scoped()` calls. Both therefore see a registry that a provider
     * WIRES and neither can see one that a call site CONSTRUCTS — and the estate has four of those, one
     * of them deliberate:
     *
     *   - `Splicewire\Tower\Models\ContentBeamSchema::fromSyncPayload()` — `new DatabaseSchemaRegistry`
     *     inside a model method;
     *   - `Splicewire\Commerce\Billing\BillGenerator::registryFor()` — `new ComponentRegistry` **per
     *     tenant**, each host component bound to that tenant so the engine's tenant-free
     *     `BillingComponent::calculate()` stays domain-agnostic. Its docblock argues it and the argument
     *     is good;
     *   - `App\Docs\Regenerate\GuideRegistry::fromConfig()`, called inside a console command's `handle()`;
     *   - `Schemastud\Blockdoc\Schema\NodeSchema`, `new`ed at three call sites in tower and
     *     `laravel-composition-engine` **in addition to** its one binding.
     *
     * Ticket 55 ruled that a per-request or per-actor registry **is a registry with a lifetime**, not a
     * defect, and that the honest response is to scope the claim rather than widen the population: an
     * AST pass for `new <class implements Registry>` outside a provider prices false positives nobody
     * has paid, and ticket 63 already refused a detector on exactly that argument. The consequence is
     * visible in the flagship and is left visible: `commerce.billing.components` is described and
     * EMPTY, while every bill the estate composes runs through a different, unindexed instance of that
     * class. That empty root is the honest artefact of a registry whose population is per-actor.
     */
    public const SCOPE = 'registries a provider WIRES (container bindings + index members). A registry '
        .'constructed at a call site — per request, per tenant, per command — is outside this population '
        .'by construction and is not a defect (registry-kernel 55).';

    protected $signature = self::SIGNATURE.'
        {--write : Rewrite the committed artifact (the default when neither --check nor --json is given)}
        {--check : Recompute and fail if `outstanding` increased; writes nothing}
        {--json : Emit the machine surface on stdout; writes nothing}
        {--path= : Artifact path (default: the configured one)}';

    protected $description = 'Write (or check) the committed artifact recording every registry-shaped class in this composition and its disposition.';

    public function handle(RegistryConformanceAudit $gate, UndeclaredRegistryShapeAudit $shapes): int
    {
        $path = (string) ($this->option('path') ?: $shapes->artifactPath());
        $rows = $shapes->artifactRows();

        if ($this->option('json')) {
            $this->line((string) json_encode($this->payload($rows, $this->liveTally($gate, $shapes), $gate), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        return $this->option('check')
            ? $this->check($path, $this->liveTally($gate, $shapes), $shapes)
            : $this->write($path, $rows, $this->writtenTally($gate, $shapes, $rows), $gate, $shapes);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $tally
     */
    private function write(string $path, array $rows, array $tally, RegistryConformanceAudit $gate, UndeclaredRegistryShapeAudit $shapes): int
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, json_encode($this->payload($rows, $tally, $gate), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->info(sprintf('Wrote %d registry row(s) to %s', count($rows), $path));
        $this->table(['bucket', 'count'], array_map(null, array_keys($tally), array_values($tally)));
        $this->line('Scope: '.self::SCOPE);
        $this->reportStaleness($shapes);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $tally
     */
    private function check(string $path, array $tally, UndeclaredRegistryShapeAudit $shapes): int
    {
        if (! is_file($path)) {
            $this->error(sprintf('No committed artifact at %s — run this command without --check to create it.', $path));

            return self::FAILURE;
        }

        $committed = json_decode((string) file_get_contents($path), true);
        $was = (int) (is_array($committed) ? ($committed['counts'][UndeclaredRegistryShapeAudit::OUTSTANDING] ?? 0) : 0);

        // `unaccounted` counts against the ratchet here even though it is a separate bucket in the report: a
        // row nobody has written down yet is a row that WILL be `outstanding` the moment anyone does, and
        // letting it sit outside the comparison would make "run --write later" a way to hide a regression.
        $now = $tally[UndeclaredRegistryShapeAudit::OUTSTANDING] + $tally[UndeclaredRegistryShapeAudit::UNACCOUNTED];

        $this->reportStaleness($shapes);

        if ($now > $was) {
            $this->error(sprintf('Outstanding registries INCREASED: %d → %d. The artifact may only ratchet downward.', $was, $now));

            foreach ($this->newlyOutstanding($committed, $shapes) as $registry) {
                $this->line("  + {$registry}");
            }

            return self::FAILURE;
        }

        $this->info($now < $was
            ? sprintf('Outstanding registries decreased: %d → %d. Commit the regenerated artifact.', $was, $now)
            : sprintf('Outstanding registries holding at %d.', $now));

        if ($now === 0 && $tally[UndeclaredRegistryShapeAudit::UNACCOUNTED] === 0) {
            $this->info('Every registry-shaped class in this composition is accounted for — the map\'s closing condition, in this host.');
            $this->line('  Scope: '.self::SCOPE);
        }

        return self::SUCCESS;
    }

    /**
     * Which registries are outstanding now but were not in the committed artifact — so a failure is a
     * work-list naming what was just added rather than only a number that went the wrong way.
     *
     * @param  mixed  $committed
     * @return list<string>
     */
    private function newlyOutstanding($committed, UndeclaredRegistryShapeAudit $shapes): array
    {
        $before = [];

        foreach ((is_array($committed) ? ($committed['registries'] ?? []) : []) as $row) {
            if (is_array($row) && is_string($row['registry'] ?? null)) {
                $before[$row['registry']] = true;
            }
        }

        $new = [];

        foreach ($shapes->artifactRows() as $row) {
            if ($row['disposition'] === UndeclaredRegistryShapeAudit::OUTSTANDING && ! isset($before[$row['registry']])) {
                $new[] = $row['registry'];
            }
        }

        return $new;
    }

    /**
     * Staleness is REPORTED on every mode rather than gated on: a stale row is a defect in the artifact
     * itself, so a run that rewrites the artifact without saying which of its rows expired has hidden the
     * one thing the run was best placed to notice.
     */
    private function reportStaleness(UndeclaredRegistryShapeAudit $shapes): void
    {
        foreach ($shapes->staleness() as $line) {
            $this->warn($line);
        }
    }

    /**
     * What the composition looks like **right now**: `conforming` from the gate's population, the three
     * dispositions and the residue from the shape audit. This is what `--check` compares and what `--json`
     * reports.
     *
     * @return array<string, int>
     */
    private function liveTally(RegistryConformanceAudit $gate, UndeclaredRegistryShapeAudit $shapes): array
    {
        return $this->conforming($gate) + $shapes->tally();
    }

    /**
     * What the artifact being written **records**, which is deliberately a different number from the one
     * above and was a real defect before it was two methods: a write PROMOTES every `unaccounted` row to
     * `outstanding`, so a `counts` block computed pre-write describes a state the file it sits in no longer
     * represents — and the very next `--check` then reads `outstanding: 0` beside a rows list full of
     * outstanding rows, comparing the ratchet against a baseline that never existed. Counting the rows
     * actually written is the only reading that cannot drift from them.
     *
     * `unaccounted` is zero here by construction, and is kept in the block rather than dropped so the two
     * shapes stay comparable key-for-key.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function writtenTally(RegistryConformanceAudit $gate, UndeclaredRegistryShapeAudit $shapes, array $rows): array
    {
        $counts = [
            UndeclaredRegistryShapeAudit::WHITELISTED => 0,
            UndeclaredRegistryShapeAudit::DEFERRED => 0,
            UndeclaredRegistryShapeAudit::OUTSTANDING => 0,
            UndeclaredRegistryShapeAudit::UNACCOUNTED => 0,
        ];

        foreach ($rows as $row) {
            $disposition = (string) $row['disposition'];

            if (array_key_exists($disposition, $counts)) {
                $counts[$disposition]++;
            }
        }

        // The whitelist lives in code, not in the artifact, so its rows are absent from `$rows` — but the
        // number belongs in the block a reviewer reads, and it is not a burn-down number that a write can
        // change. Taken from the live count, which is over the same set either way.
        $counts[UndeclaredRegistryShapeAudit::WHITELISTED] = $shapes->tally()[UndeclaredRegistryShapeAudit::WHITELISTED];

        return $this->conforming($gate) + $counts;
    }

    /**
     * @return array<string, int>
     */
    private function conforming(RegistryConformanceAudit $gate): array
    {
        return [UndeclaredRegistryShapeAudit::CONFORMING => count(array_filter(
            $gate->declarations(),
            fn (array $row) => $row['failures'] === [],
        ))];
    }

    /**
     * The artifact body. `counts` sits beside the rows so a reviewer reads the numbers off the diff header
     * without counting entries, and `non_conforming` names the declared-but-broken set the gate is failing
     * on — the two audits' outputs are one story and splitting them across two files would mean reading
     * both to answer one question.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $tally
     * @return array<string, mixed>
     */
    private function payload(array $rows, array $tally, RegistryConformanceAudit $gate): array
    {
        return [
            'check' => UndeclaredRegistryShapeAudit::CHECK,
            'gate' => RegistryConformanceAudit::CHECK,
            'scope' => self::SCOPE,
            'counts' => $tally,
            // Committed rather than merely reported, on the same argument the rest of this file makes: this
            // failure is silent in production (one absolute key answering two ways depending on the door),
            // so the place it must be visible is a review diff. Empty is the expected state and an empty
            // list is deterministic, so a no-op re-run stays byte-identical.
            'shadowed' => $gate->shadowedEntries(),
            'non_conforming' => array_map(
                fn (array $row) => ['registry' => $row['registry'], 'root' => $row['root'], 'failures' => $row['failures']],
                $gate->nonConforming(),
            ),
            'registries' => $rows,
        ];
    }
}
