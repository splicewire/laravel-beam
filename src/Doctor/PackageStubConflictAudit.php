<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\SchemaConvergence\SchemaConflict;
use Splicewire\Beam\Install\MigrationRehearsal;
use Splicewire\Beam\Install\PackageStubs;
use Splicewire\Beam\Install\RehearsalSafety;

/**
 * **Which package stubs would THROW if they were published here today** (beam-facade ticket 182).
 *
 * ## The blind spot, and why it took a third audit to see it
 *
 * Beam already carried two audits with deliberately opposite scoping, and each says so about the other.
 * {@see UnrehearsableStubAudit} reads the PUBLISHED copies, because its subject is what this host will
 * *run*. {@see UnguardedCreateAudit} reads the `.php.stub` TEMPLATES, because its subject is what a
 * package *declares*. **Neither reads a package stub against the live database**, and that is exactly
 * where the estate's shape-ownership mechanism hides its cost.
 *
 * The mechanism is correct and nothing here asks to change it: beam-facade ticket 108 ruled that a host
 * disagreeing with a package's declared shape **keeps its own published copy**, and the package stub
 * defers to it. What 108 also found is that the deferral is invisible. A host override occupies the
 * stem, so the stub drops out of `MigrationFiles::pathsFor()` **forever** — the install-time preflight
 * rehearses the *override*, finds it matches live, and passes, while the package stub sits one republish
 * away from a convergent tier-three throw that no instrument in the estate has ever mentioned. That is
 * this estate's recurring defect class — *an instrument that reports success by not running* — in the
 * shape 108 measured. This audit is the instrument that runs.
 *
 * Measured at `~/Herd/audiostud`, 2026-08-27: four conflicts across two tables, both shielded by a
 * correct and deliberate host override — `activity_log.subject_id` and `.causer_id` are `uuid` live
 * while beam's stub declares `string`; `impersonation_events.actor_id` and `.subject_id` are `uuid`
 * while beam-accounts' stub declares `string`. Nothing there is to be repaired. `activity_log` holds
 * 154 rows, and the day someone republishes that stub — a reset, an install at a host whose set was
 * rebuilt, a drift-audit "repair" — the guard throws. Now it is said first.
 *
 * ## Advisory, PERMANENTLY — and the reason, not just the severity
 *
 * Under `rushing/laravel-doctor/docs/agents/gate-or-advisory.convention.md`, axis 1 asks *whose fact is
 * this*. Whether this host's live `activity_log` is uuid-keyed is a fact about the **host**: the author
 * of the stub could not have known audiostud would arrive that way, and the estate's own doctrine says
 * arriving that way is legitimate — the accounts stub's docblock sanctions these two overrides
 * verbatim. Axis 2 has no opt-in to point at either; a host does not register to be told about a
 * package's stubs. A gate here would brick exactly the hosts that are behaving correctly, which is the
 * tower-boot failure this estate has already paid for once. **There is no promotion trigger. Do not add
 * one.** The finding is a hazard notice, not a defect to burn down.
 *
 * ## Loudness comes from specificity, not from severity
 *
 * A divergence that would NOT conflict is not a finding at all — convergence adds what is missing, so a
 * stub declaring a column the live table lacks is routine and silent. Only a tier-three
 * {@see SchemaConflict} is reported, one finding per conflicting column, naming the republish that would
 * throw and the two disagreeing types. That is what keeps this population at four rather than joining
 * `PublishedMigrationDriftAudit`'s 314-copy noise floor, which flags every content divergence and cannot
 * tell a fossil from a deliberate host edit.
 *
 * ## Coverage is reported, because a silent skip would rebuild the blind spot
 *
 * {@see RehearsalSafety} gates what may be rehearsed without writing, and a stub it refuses is **skipped
 * and counted**, never guessed at. **Do not widen that predicate to raise this audit's coverage** — a
 * `DB::statement()` beside a guard would execute for real during a pass that promised to write nothing.
 * A stub whose `up()` reaches a class, config key or connection this host does not have is likewise
 * counted rather than crashing the run. Both counts are stated in every line this audit emits: "no
 * conflicts" and "nothing could be asked" are different facts, and an advisory that could not run says
 * so as a Warn (the convention's own rule for an instrument whose subject went unverified).
 */
class PackageStubConflictAudit implements DoctorAudit
{
    public const CHECK = 'beam.schema.package-stub-conflict';

    /** @param string $hostRoot the app root whose `vendor/composer/installed.json` names the population */
    public function __construct(protected string $hostRoot) {}

    public static function forApp(): self
    {
        return new self(base_path());
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $convergent = 0;
        $rehearsed = 0;
        $skipped = 0;
        $rows = [];

        foreach (PackageStubs::forHost($this->hostRoot) as $stub) {
            $source = (string) @file_get_contents($stub['file']);

            if (! RehearsalSafety::isConvergent($source)) {
                continue;
            }

            $convergent++;

            $result = MigrationRehearsal::of($stub['stem'], $stub['file'], $source);

            if (! $result->wasRehearsed()) {
                $skipped++;

                continue;
            }

            $rehearsed++;

            foreach ($result->conflicts() as $conflict) {
                $rows[] = ['stub' => $stub, 'conflict' => $conflict];
            }
        }

        if ($convergent === 0) {
            return [Finding::inconclusive(self::CHECK, sprintf(
                'No installed package ships a convergent migration template into this host, so there is '.
                'no package-declared shape that could disagree with the live database. (%s reads the '.
                'published copies this host will run; %s reads the same templates for a missing guard. '.
                'Neither is this number.)',
                UnrehearsableStubAudit::CHECK,
                UnguardedCreateAudit::CHECK,
            ))];
        }

        if ($rehearsed === 0) {
            return [Finding::warn(self::CHECK, sprintf(
                'None of the %d convergent package template(s) installed here could be rehearsed against '.
                'the live database (%d skipped), so this check verified NOTHING. An unverified advisory '.
                'is not a pass. The usual cause is a database this command cannot reach; the other is a '.
                'population that is entirely raw-DDL, which %s measures.',
                $convergent,
                $skipped,
                UnrehearsableStubAudit::CHECK,
            ))];
        }

        if ($rows === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'Every one of the %d package migration template(s) rehearsed against this database can '.
                'land as declared%s — republishing any of them would converge, not throw. This reads '.
                'PACKAGE STUBS against live schema, which is the opposite scoping from %s (the published '.
                'copies this host will run) and from %s (the same templates read for a missing guard); '.
                'no one of the three numbers is another\'s.',
                $rehearsed,
                $skipped === 0 ? '' : sprintf(
                    ', and %d more could not be asked (a body a rehearsal cannot neutralise, or one this '.
                    'host cannot resolve)',
                    $skipped,
                ),
                UnrehearsableStubAudit::CHECK,
                UnguardedCreateAudit::CHECK,
            ))];
        }

        $findings = [Finding::warn(self::CHECK, sprintf(
            '%d column(s) across %d package migration template(s) declare a shape this database cannot '.
            'converge onto, so publishing those templates here would THROW. The table exists in another '.
            'shape already — a host-published copy, or another package\'s migration, got there first and '.
            'is holding the line. **That is usually the estate\'s shape-ownership mechanism working '.
            '(beam-facade 108), not a defect**, and the copy in this host\'s tree is not to be repaired '.
            'on the strength of this finding. What is reported is that the arrangement is LOAD-BEARING '.
            'and undeclared: a reset, a `beam:install` at a host whose set was rebuilt, or a '.
            '`vendor:publish --force` removes it and the guard throws. This audit does not claim to know '.
            'WHICH file is holding each table — a host copy can shield a stub under a different stem '.
            '(measured at audiostud: `create_tag_tables` shields `create_tags_table`), so a stem match '.
            'would report a shielded table as unshielded. Advisory permanently — the live shape is a '.
            'fact about this host, which the template\'s author could not have known. Rehearsed %d of %d '.
            'convergent template(s)%s.',
            count($rows),
            count(array_unique(array_map(fn (array $row) => $row['stub']['file'], $rows))),
            $rehearsed,
            $convergent,
            $skipped === 0 ? '' : sprintf('; %d could not be asked', $skipped),
        ))];

        foreach ($rows as $row) {
            $findings[] = Finding::warn(self::CHECK, $this->hazard($row['stub'], $row['conflict']));
        }

        return $findings;
    }

    /**
     * One conflicting column, phrased as the republish that would fail rather than as a divergence — the
     * hazard is the thing an operator has to decide about, and "these two files differ" is what the drift
     * audit already says 314 times.
     *
     * @param  array{package: string, file: string, stem: string}  $stub
     */
    private function hazard(array $stub, SchemaConflict $conflict): string
    {
        return sprintf(
            'Republishing `%s` (%s) would THROW at `%s`: %s Keep the host\'s own published copy of this '.
            'table, or reconcile the two declarations deliberately — do not republish over it expecting '.
            'convergence to top it up.',
            $stub['stem'],
            $stub['package'],
            $conflict->table.'.'.$conflict->column,
            $this->diagnosis($conflict),
        );
    }

    /**
     * The conflict's first sentence — the decidable fact (`live is X, the template declares Y`), without
     * the paste-ready repair sketch the guard also carries. The sketch belongs to whoever is doing the
     * reconciling; a doctor line that carried three of them per finding would bury the count.
     */
    private function diagnosis(SchemaConflict $conflict): string
    {
        $detail = trim($conflict->detail);
        $end = strpos($detail, '. ');

        return $end === false ? $detail : substr($detail, 0, $end + 1);
    }
}
