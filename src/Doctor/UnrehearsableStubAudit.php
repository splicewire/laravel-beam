<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Install\MigrationFiles;
use Splicewire\Beam\Install\RehearsalSafety;

/**
 * How much of this host's convergent population the install-time preflight cannot see
 * (beam-facade ticket 109).
 *
 * ## The gap this closes
 *
 * `ConvergencePreflight` (ticket 84) rehearses a migration by running its `up()` with every convergent
 * terminal in report mode, so it refuses to rehearse any body it cannot prove is a pure convergent
 * declaration — a `DB::statement()` or a post-create `Schema::table()` beside a guard would execute for
 * real during a pass that promises to write nothing. That refusal is correct and is not what this audit
 * questions.
 *
 * What it fixes is that the refusal was only ever VISIBLE to an operator who happened to be running
 * `splicewire:beam:install` and happened to read the `?` lines. There is no report-only entry point to
 * the preflight — it is a phase of a command that publishes and migrates — so "how much of this host is
 * unmeasured" was a number each session rediscovered by installing. Ticket 109 ruled option 3: accept
 * that a convergent stub may carry non-convergent DDL, and instrument the cost. This is the instrument.
 *
 * ## The count is DERIVED, never written down
 *
 * `convergent-migration-guards.convention.md` recorded "nine files in the estate do", and by 2026-08-26
 * that number was **16** under its own predicate and **21** under the preflight's wider one, out of 141
 * convergent stubs. A count in prose is a count that goes stale silently, which is the whole reason this
 * audit exists rather than a sentence somewhere. Read the number here.
 *
 * ## Advisory, permanently
 *
 * Whether a stub carrying raw DDL is published into THIS host is a fact about the host's composition,
 * not about the declaration — the author of `create_fragments_table` could not have known which hosts
 * would install `laravel-satellite-knowledge`. Under
 * `rushing/laravel-doctor/docs/agents/gate-or-advisory.convention.md` that makes it an advisory finding
 * and never a gate: axis 1 (whose fact is it) puts it on the host side, and axis 2 (did the population
 * opt in) has no opt-in to point at. The estate's ruling is that the DDL is legitimate — each of these
 * bodies carries its own hand-written idempotency guard, and a self-referencing foreign key genuinely
 * cannot be expressed through convergence (ticket 27 ruled FKs and PKs out of the converge path
 * deliberately). So a finding here is *coverage information*, not a defect to burn down.
 *
 * ## Scope
 *
 * The same population the next `migrate` would read — {@see MigrationFiles::pathsFor()}, non-recursive
 * per registered path, exactly the migrator's own enumeration. Deliberately the PUBLISHED copies rather
 * than the package stubs: the preflight's blindness is about what this host will run, and a host that
 * never installed the arm shipping a raw-DDL stub has no gap. That is the opposite scoping from
 * {@see UnguardedCreateAudit}, which reads `.php.stub` templates because its subject is what a package
 * declares. The two audits are about different things and neither number is the other's.
 *
 * **There is now a third scoping, and it is the one this pair could not reach.**
 * {@see PackageStubConflictAudit} (beam-facade ticket 182) rehearses PACKAGE STUBS against the LIVE
 * DATABASE. Note what that means for the population here: a host that keeps its own published copy of a
 * table takes the package stub out of `MigrationFiles::pathsFor()` **forever**, so this audit rehearses
 * the override, finds it clean, and is silent about the declaration it displaced. That silence is
 * correct — the subject here is what this host will run — and it is exactly why the third audit exists.
 * Three scopings, three numbers, none of them each other's.
 */
class UnrehearsableStubAudit implements DoctorAudit
{
    public const CHECK = 'beam.schema.unrehearsable-stub';

    /** @param list<string> $paths every migration path the next `migrate` will read */
    public function __construct(protected array $paths) {}

    public static function forApp(): self
    {
        return new self(MigrationFiles::pathsFor(app()));
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $convergent = 0;
        $rows = [];

        foreach (MigrationFiles::in($this->paths) as [, , $file]) {
            $source = (string) @file_get_contents($file);

            if (! RehearsalSafety::isConvergent($source)) {
                continue;
            }

            $convergent++;

            $reason = RehearsalSafety::reasonFor($source);

            if ($reason !== null) {
                $rows[] = ['file' => $file, 'reason' => $reason];
            }
        }

        if ($convergent === 0) {
            return [Finding::pass(self::CHECK, sprintf(
                'No convergent migration is published into this host (%d migration path(s) scanned), so '.
                'the install-time preflight has nothing it cannot see.',
                count($this->paths),
            ))];
        }

        if ($rows === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'All %d convergent migration(s) published here are pure convergent declarations, so the '.
                'install-time preflight can rehearse every one of them before `migrate` runs.',
                $convergent,
            ))];
        }

        $findings = [Finding::warn(self::CHECK, sprintf(
            '%d of %d convergent migration(s) published here cannot be rehearsed (%d%%), so the '.
            'install-time preflight reports on the rest and says nothing about these. That is a coverage '.
            'reading, not a defect: the raw DDL beside these guards is deliberate and each body carries '.
            'its own idempotency guard. What it costs is that a shape conflict inside one of them is '.
            'discovered by `migrate` failing, not by the preflight aborting.',
            count($rows),
            $convergent,
            (int) round(count($rows) / $convergent * 100),
        ))];

        foreach ($rows as $row) {
            $findings[] = Finding::warn(self::CHECK, sprintf(
                '%s carries %s, which a rehearsal would run for real.',
                basename($row['file'], '.php'),
                $row['reason'],
            ));
        }

        return $findings;
    }
}
