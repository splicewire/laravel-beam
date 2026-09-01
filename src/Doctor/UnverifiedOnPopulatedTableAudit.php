<?php

namespace Splicewire\Beam\Doctor;

use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\SchemaConvergence\ColumnTypeEquivalence;
use Rushing\SchemaConvergence\ConvergenceReport;
use Rushing\SchemaConvergence\ConvergentTable;
use Splicewire\Beam\Install\MigrationFiles;
use Splicewire\Beam\Install\MigrationRehearsal;
use Splicewire\Beam\Install\PackageStubs;
use Splicewire\Beam\Install\RehearsalSafety;
use Throwable;

/**
 * **Which `unverified` column sits on a table that exists and HOLDS ROWS** (beam-facade ticket 187).
 *
 * ## The question, and why it is beam-tier by construction
 *
 * `ColumnTypeEquivalence::matches()` has three answers, not two, and the third is `null` — *"this build
 * has no mapping for that declared type against that live type"*. {@see ConvergentTable} collects those
 * into {@see ConvergenceReport::$unverified} and reports them rather than guessing, on the stated trade
 * *"a false conflict stops an install; a missed one leaves the status quo"*. Ticket 184 proposes
 * escalating an `unverified` to a refusal **when and only when the table holds rows**, because against
 * live data that trade inverts. This audit is the instrument that says which columns those are, before
 * anything refuses anything.
 *
 * Its sibling in `rushing/laravel-surgeon` — `UnmappedConvergentTypeAudit`, ticket 183a — answers the
 * *types* half from stub text alone, and reaches the five beamless `rushing/*` packages this audit
 * cannot. It stops exactly where a text scan must: **pairing a declared type with a live column means
 * executing the declaration**, and the thing that does that is {@see MigrationRehearsal}, which lives
 * here. Surgeon is a foundation package whose `extra.package-topology.mustNotRequire` names
 * `splicewire/*`, so this half could not stay there. That tier cost is real and is not hidden: an
 * operator of a beamless package learns which of its declared types are unmapped and never whether any
 * of them is populated.
 *
 * ## Two populations, because a migration refuses at the moment it RUNS
 *
 * - **Published copies** ({@see MigrationFiles}) — what the next `migrate` at this host will run, so an
 *   escalation would refuse *here, next migrate*.
 * - **Package templates** ({@see PackageStubs}) — what a `vendor:publish` would stamp in, so an
 *   escalation would refuse *on republish*. This is the population {@see PackageStubConflictAudit}
 *   exists for, and the reason is the same: a host override occupies the stem, the stub drops out of
 *   `MigrationFiles` forever, and nothing ever asks it anything.
 *
 * A pairing found in both is reported **once**, keyed `table.column`, with both provenances named. The
 * counts are stated per population and never summed into one number that means neither.
 *
 * ## The row read is the SAME read, not a second condition
 *
 * `ConvergentTable::converge()` already asks `$schema->getConnection()->table($this->table)->exists()`,
 * memoised, for the required-addition refusal (`rushing/laravel-schema-convergence/src/ConvergentTable.php:329`
 * — ⚠️ three artifacts still cite that line as `:322`, and as `laravel-beam`, which is where the whole
 * convergence family lived until `ac90071` on 08-19). Ticket 115 ruled explicitly against minting a
 * second condition that would then have to stay in agreement with the first, so this asks the identical
 * question of the identical builder — no config key, no `APP_ENV`, no per-host declaration, all three of
 * which 115 considered and withdrew and which `gate-or-advisory.convention.md` independently forbids.
 *
 * ## Advisory, PERMANENTLY
 *
 * *"Does this table hold rows here?"* is definitionally a fact about the **host**. Under
 * `rushing/laravel-doctor/docs/agents/gate-or-advisory.convention.md` that licenses an advisory finding
 * and never a fatal — the rule this estate learned by making `~/Herd/tower` unbootable with a throw
 * whose answer was true at the flagship. There is no promotion trigger here. Whether the *guard* should
 * escalate is ticket 184's question and is genuinely contested (the counter-precedent is
 * `SchemaConflict::requiredAddition`, already a host-dependent fatal gated on this very read); nothing
 * about that argument is settled by this audit reporting the population.
 *
 * ⚠️ **And the population argues against the escalation, which is the finding, not a footnote.** The
 * estate's only unmapped declared type is `enum`, and Laravel compiles `enum()` on Postgres to
 * `varchar` plus a CHECK constraint — so the one pairing that exists is a **correct** column that the
 * map simply cannot judge. An escalation shipped ahead of the `enum` mapping converts it into an
 * install-stopping refusal, at the flagship, on a table with rows.
 *
 * ## What it cannot reach, counted rather than assumed
 *
 * The lesson this audit is built against is that **an instrument enumerating its known blind spots and
 * not its unknown ones reads as thorough precisely where it is weakest** — measured twice in one week,
 * once when a type census counted four stubs it had read nothing out of, once when a key-type index
 * read one create verb. So every line below states what could not be asked, and one finding exists for
 * nothing but that:
 *
 * - a body {@see RehearsalSafety} refuses is **skipped and counted**, never guessed at. Do not widen
 *   that predicate to raise this audit's coverage — a `DB::statement()` beside a guard would execute
 *   for real during a pass that promised to write nothing;
 * - a body that rehearses and yields **zero convergence reports** is counted as *unread*: it named
 *   `ConvergentTable` and this audit learned nothing from it, which is a different fact from "it was
 *   clean";
 * - {@see ConvergenceReport} carries a table name and no **connection**, so the row read is taken on
 *   the default connection. A guard pinned elsewhere presents here as a table that is not there, and is
 *   counted as a row read this audit could not take — never as "not populated", which is the answer
 *   that would read like coverage.
 */
class UnverifiedOnPopulatedTableAudit implements DoctorAudit
{
    public const CHECK = 'beam.schema.unverified-populated';

    /** Memoised per table, exactly as the guard memoises it: true, false, or null for "could not ask". */
    private array $populated = [];

    /** Tables whose row read could not be taken here, with why — the counter, not a silent skip. */
    private array $unreadableRows = [];

    /**
     * @param  string  $hostRoot  the app root whose `vendor/composer/installed.json` names the templates
     * @param  list<string>  $migrationPaths  the paths the next `migrate` will read
     */
    public function __construct(protected string $hostRoot, protected array $migrationPaths = []) {}

    public static function forApp(): self
    {
        return new self(base_path(), MigrationFiles::pathsFor(app()));
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $published = $this->rehearse($this->publishedPopulation());
        $templates = $this->rehearse($this->templatePopulation());

        $convergent = $published['convergent'] + $templates['convergent'];
        $rehearsed = $published['rehearsed'] + $templates['rehearsed'];

        if ($convergent === 0) {
            return [Finding::inconclusive(self::CHECK, sprintf(
                'Nothing here declares a convergent table — no published migration this host will run '.
                'and no template an installed package ships — so there is no declared type that could '.
                'go `unverified` against a populated table. (`%s` answers the TYPES half from stub text '.
                'across the estate including the beamless packages this cannot see; it is not this '.
                'number and never pairs a type with a live column.)',
                'unmapped-convergent-type',
            ))];
        }

        if ($rehearsed === 0) {
            return [Finding::warn(self::CHECK, sprintf(
                'None of the %d convergent declaration(s) here could be rehearsed against the live '.
                'database (%d skipped), so this check verified NOTHING. An unverified advisory is not a '.
                'pass. The usual cause is a database this command cannot reach; the other is a '.
                'population that is entirely raw-DDL, which %s measures.',
                $convergent,
                $published['skipped'] + $templates['skipped'],
                UnrehearsableStubAudit::CHECK,
            )),
                $this->reachFinding($published, $templates),
            ];
        }

        $pairings = $this->pairings($published['reports'], $templates['reports']);

        $findings = $pairings === []
            ? [Finding::pass(self::CHECK, $this->clearDetail($published, $templates))]
            : [Finding::warn(self::CHECK, $this->headline($pairings, $published, $templates))];

        foreach ($pairings as $pairing) {
            $findings[] = Finding::warn(self::CHECK.'.pairing', $this->hazard($pairing));
        }

        $findings[] = $this->reachFinding($published, $templates);

        return $findings;
    }

    /**
     * The published copies this host will run, as [name, file]. A stem is enough to name a finding by;
     * the stamped filename is what the migrator records, so that is what is reported.
     *
     * @return list<array{provenance: string, name: string, file: string}>
     */
    private function publishedPopulation(): array
    {
        $files = [];

        foreach (MigrationFiles::in($this->migrationPaths) as [$prefix, $stem, $file]) {
            $files[] = ['provenance' => 'published here', 'name' => $prefix.'_'.$stem, 'file' => $file];
        }

        return $files;
    }

    /**
     * @return list<array{provenance: string, name: string, file: string}>
     */
    private function templatePopulation(): array
    {
        $files = [];

        foreach (PackageStubs::forHost($this->hostRoot) as $stub) {
            $files[] = [
                'provenance' => 'template shipped by '.$stub['package'],
                'name' => $stub['stem'],
                'file' => $stub['file'],
            ];
        }

        return $files;
    }

    /**
     * Rehearse one population and keep BOTH outcomes. `unread` is the counter this audit's own class of
     * defect required: a file that named the guard, rehearsed without error, and produced no report at
     * all is not evidence of anything, and folding it into `rehearsed` is how a census comes to read
     * thorough exactly where it is blind.
     *
     * @param  list<array{provenance: string, name: string, file: string}>  $population
     * @return array{convergent: int, rehearsed: int, skipped: int, unread: list<string>, reports: list<array{source: array, report: ConvergenceReport}>}
     */
    private function rehearse(array $population): array
    {
        $convergent = 0;
        $rehearsed = 0;
        $skipped = 0;
        $unread = [];
        $reports = [];

        foreach ($population as $source) {
            $body = (string) @file_get_contents($source['file']);

            if (! RehearsalSafety::isConvergent($body)) {
                continue;
            }

            $convergent++;

            $result = MigrationRehearsal::of($source['name'], $source['file'], $body);

            if (! $result->wasRehearsed()) {
                $skipped++;

                continue;
            }

            $rehearsed++;

            if ($result->reports === []) {
                $unread[] = basename($source['file']);

                continue;
            }

            foreach ($result->reports as $report) {
                $reports[] = ['source' => $source, 'report' => $report];
            }
        }

        return compact('convergent', 'rehearsed', 'skipped', 'unread', 'reports');
    }

    /**
     * Every `unverified` column whose table holds rows, keyed `table.column` so a stub and the published
     * copy stamped from it are ONE pairing carrying two provenances rather than two findings that look
     * like two hazards.
     *
     * @param  list<array{source: array, report: ConvergenceReport}>  $published
     * @param  list<array{source: array, report: ConvergenceReport}>  $templates
     * @return list<array{table: string, column: string, provenances: list<string>}>
     */
    private function pairings(array $published, array $templates): array
    {
        $pairings = [];

        foreach (array_merge($published, $templates) as $row) {
            $report = $row['report'];

            if ($report->unverified === []) {
                continue;
            }

            if ($this->isPopulated($report->table) !== true) {
                continue;
            }

            foreach ($report->unverified as $column) {
                $key = $report->table.'.'.$column;

                $pairings[$key] ??= ['table' => $report->table, 'column' => $column, 'provenances' => []];

                $provenance = $row['source']['name'].' ('.$row['source']['provenance'].')';

                if (! in_array($provenance, $pairings[$key]['provenances'], true)) {
                    $pairings[$key]['provenances'][] = $provenance;
                }
            }
        }

        ksort($pairings);

        return array_values($pairings);
    }

    /**
     * The guard's own read, memoised the same way. Three answers, not two: a table this connection does
     * not carry is `null` — *"could not ask here"* — and never `false`, because a guard pinned to
     * another connection reporting as "not populated" is precisely the false green this audit is built
     * to refuse to produce.
     */
    private function isPopulated(string $table): ?bool
    {
        if (array_key_exists($table, $this->populated)) {
            return $this->populated[$table];
        }

        try {
            $schema = Schema::connection(null);

            if (! $schema->hasTable($table)) {
                $this->unreadableRows[$table] = 'absent on the default connection — the report carries no '
                    .'connection name, so a guard pinned to another one cannot be read here';

                return $this->populated[$table] = null;
            }

            return $this->populated[$table] = $schema->getConnection()->table($table)->exists();
        } catch (Throwable $e) {
            $this->unreadableRows[$table] = 'row read failed: '.$e->getMessage();

            return $this->populated[$table] = null;
        }
    }

    /** @param array{table: string, column: string, provenances: list<string>} $pairing */
    private function hazard(array $pairing): string
    {
        return sprintf(
            '`%s.%s` declares a type `ColumnTypeEquivalence` has no mapping for, and the table HOLDS '.
            'ROWS — so this is the pairing a populated-table escalation (ticket 184) would newly refuse. '.
            'Declared by: %s. Convergence reports it and does nothing, which is the current and correct '.
            'behaviour: the map returning `null` means *unjudged*, NOT *wrong*, and the estate\'s one '.
            'known instance is a column Laravel compiled correctly (`enum()` becomes `varchar` plus a '.
            'CHECK on Postgres). Read this as an argument for adding the missing mapping, not as a '.
            'defect in the migration.',
            $pairing['table'],
            $pairing['column'],
            implode(', ', $pairing['provenances']),
        );
    }

    /** @param list<array{table: string, column: string, provenances: list<string>}> $pairings */
    private function headline(array $pairings, array $published, array $templates): string
    {
        return sprintf(
            '%d column(s) across %d table(s) declare a type this build cannot judge, on a table that '.
            'holds rows. Advisory permanently — whether a table is populated HERE is a fact about this '.
            'host, which the declaration\'s author could not have known, and this estate has already '.
            'paid once for a host-dependent throw. Rehearsed %d of %d convergent declaration(s) '.
            '(%d published copy/copies this host will run, %d package template(s) a publish would stamp '.
            'in)%s.',
            count($pairings),
            count(array_unique(array_map(static fn (array $p): string => $p['table'], $pairings))),
            $published['rehearsed'] + $templates['rehearsed'],
            $published['convergent'] + $templates['convergent'],
            $published['convergent'],
            $templates['convergent'],
            $this->skipNote($published, $templates),
        );
    }

    private function clearDetail(array $published, array $templates): string
    {
        return sprintf(
            'No declared column type that this build cannot judge sits on a table holding rows here. '.
            'Rehearsed %d of %d convergent declaration(s) (%d published copy/copies, %d package '.
            'template(s))%s — every `unverified` verdict they produced, if any, was on a table that is '.
            'empty or absent. Nothing here would newly refuse under a populated-table escalation '.
            '(ticket 184).',
            $published['rehearsed'] + $templates['rehearsed'],
            $published['convergent'] + $templates['convergent'],
            $published['convergent'],
            $templates['convergent'],
            $this->skipNote($published, $templates),
        );
    }

    private function skipNote(array $published, array $templates): string
    {
        $skipped = $published['skipped'] + $templates['skipped'];

        return $skipped === 0 ? '' : sprintf(
            '; %d more could not be asked (a body a rehearsal cannot neutralise, or one this host cannot '.
            'resolve)',
            $skipped,
        );
    }

    /**
     * What this run could NOT see. Emitted on every outcome including a clean one, because the whole
     * value of the counter is that it stands beside the pass — an instrument that only enumerates its
     * blind spots when it already has something to report is not enumerating them.
     */
    private function reachFinding(array $published, array $templates): Finding
    {
        $unread = array_values(array_unique(array_merge($published['unread'], $templates['unread'])));
        $skipped = $published['skipped'] + $templates['skipped'];
        $rows = $this->unreadableRows;

        $detail = sprintf(
            'Reach: %d of %d convergent declaration(s) were rehearsed; %d were skipped unrehearsed; %d '.
            'rehearsed and yielded NO convergence report; %d table(s) named by a report could not have '.
            'their rows read here. The map this is measured against covers %d declared type(s), so a '.
            'type outside it is what produces an `unverified` verdict in the first place.',
            $published['rehearsed'] + $templates['rehearsed'],
            $published['convergent'] + $templates['convergent'],
            $skipped,
            count($unread),
            count($rows),
            count(ColumnTypeEquivalence::mappedTypes()),
        );

        if ($unread !== []) {
            $detail .= sprintf(
                ' Read nothing out of: %s. Either the body reaches its guard behind a condition this '.
                'rehearsal did not satisfy, or it names `ConvergentTable` without calling a terminal — '.
                'reported rather than absorbed, because counting these as rehearsed is how a census '.
                'reads thorough precisely where it is blind.',
                implode(', ', array_slice($unread, 0, 6)).(count($unread) > 6 ? sprintf(' (+%d more)', count($unread) - 6) : ''),
            );
        }

        if ($rows !== []) {
            $detail .= ' Rows unread: '.implode('; ', array_map(
                static fn (string $table, string $why): string => $table.' — '.$why,
                array_keys($rows),
                array_values($rows),
            )).'.';
        }

        return $unread === [] && $rows === [] && $skipped === 0
            ? Finding::pass(self::CHECK.'.reach', $detail)
            : Finding::warn(self::CHECK.'.reach', $detail);
    }
}
