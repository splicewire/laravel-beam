<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\SchemaConvergence\ConvergentTable;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Doctor\Support\SchemaCreateScanner;

/**
 * **A migration template that publishes a table must create it convergently** — no raw `Schema::create`
 * (beam-facade tickets 22 Q8a and 30; the rule itself lives with the utility, in
 * `rushing/laravel-schema-convergence/docs/agents/convergent-migration-guards.convention.md`).
 *
 * ## Why the family needed a sixth audit at all
 * Tickets 27–29 closed the migration-collision family at its mechanism: {@see ConvergentTable} settles
 * every collision *inside* the family (create when absent, top up when present, throw on a real
 * conflict), and the installer's filename re-dating settles the half where the competitor's guard
 * belongs to a vendor we cannot edit. Both are runtime mechanisms, and with the guard self-enforcing at
 * migrate time the only thing left to check statically is a stub someone wrote **without** it. That is
 * what actually happened, three times over: nobody forgot the rule, they forgot to write the guard in a
 * new file. Ticket 15 watched a stub be *born* importing the class its sweep was removing, mid-sweep.
 *
 * ## What it flags, and what that costs
 * An executable `Schema::create()` in a `.php.stub` under `database/migrations/`. A converted stub does
 * not wrap the create, it replaces it, so presence-of-create *is* absence-of-guard and there is nothing
 * further to detect — see {@see SchemaCreateScanner} for the three hazards that collapse falls out of
 * (no class name, no table identity, per call rather than per file).
 *
 * **Importing the guard is necessary, not sufficient, and this audit only checks the necessary half.**
 * Ticket 28 measured the gap while sweeping: six converted stubs carry raw `DB::statement` DDL beside
 * the create (partial unique indexes, a tsvector GIN index and its trigger, a CHECK constraint) and
 * three add a self-referencing FK in a post-create `Schema::table()`. Convergence covers none of it and
 * each needed a hand-written idempotency guard. This check reads all nine as conformant, and says so
 * here rather than letting a green run be read as certifying more than it looked at.
 *
 * **The risk it cannot reach at all is the host copy.** 19's exclude-dated-published-migrations rule
 * stands — flagging generated output nominates hand-editing it — but 28 measured the consequence: every
 * published copy at `~/Herd/splicewire-app` was still the pre-sweep create-only shape until 28's gate
 * republished them, and the gate reverted that. **A swept package beside an unswept host is the estate's
 * normal state**, and the repair is `vendor:publish --force` plus `splicewire:beam:install`, not an edit
 * to the file this audit would have named. The Pass line says so, because silence there is what would
 * make a green run misleading.
 *
 * ## Substrate and scope, inherited from 19's regime
 * Doctor, not surgeon, for the same structural reason {@see StubStaticReferenceAudit} is: surgeon
 * hardcodes the `php` extension in `AuditEngine::phpFilesIn()` and is blind to `.php.stub`, which is
 * this check's entire population. It shares {@see FacadeConformanceScope} — the memoized walk, the
 * symlinked-`vendor/`-only resolution-mode rule, the `tests/` and published-copy exclusions — despite
 * not being a facade check, because the scope's real subject is *the authorable family surface of this
 * host* and building a second walk to say so would cost a second walk in a command that already OOMs at
 * 128 MB. The name is narrower than the thing; recorded rather than renamed.
 *
 * Two acceptance conditions of ticket 30 are met by that scope rather than by an exception, which is
 * worth stating because an exception would have had to be maintained: the guard utility's own tests
 * live under `tests/`, and so does beam's `CleanStub` doctor fixture — the estate's one unregistered
 * stub, which ticket 30 expected to have to exempt. It carries no create at all, so it is invisible
 * twice over and "clean" keeps meaning what it meant.
 *
 * Advisory ({@see \Splicewire\Beam\BeamServiceProvider::registerFacadeConformanceAudits()}).
 */
class UnguardedCreateAudit implements DoctorAudit
{
    public const CHECK = 'beam.schema.unguarded-create';

    public function __construct(protected FacadeConformanceScope $scope) {}

    public static function forApp(?FacadeConformanceScope $scope = null): self
    {
        return new self($scope ?? FacadeConformanceScope::forApp());
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $rows = $this->creates();

        if ($rows === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'Every migration template creates its tables convergently (%d template(s) scanned). '.
                'Note the two things this does not cover: raw DDL beside a convergent create (28 found 9 '.
                'such files, each carrying its own hand-written idempotency guard), and copies already '.
                'published into a host — those are excluded as generated output, and a host still on the '.
                'pre-guard shape is repaired by re-publishing, not by an edit.',
                count($this->templates()),
            ))];
        }

        return array_map(fn (array $row): Finding => Finding::warn(self::CHECK, sprintf(
            '%s:%d calls %s() directly. Whichever migration sorts first wins and the loser reports '.
            'success, so a host that already holds this table silently keeps the other shape. Create it '.
            'through %s instead — see convergent-migration-guards.convention.md.',
            $row['file'],
            $row['line'],
            $row['shape'],
            ConvergentTable::class,
        )), $rows);
    }

    /**
     * Every raw create in scope, as sorted rows — the work-list.
     *
     * @return list<array{file: string, line: int, shape: string}>
     */
    public function creates(): array
    {
        $rows = [];

        foreach ($this->scope->sourcesContaining(['Schema']) as $path => $source) {
            if (! static::isMigrationTemplate($path)) {
                continue;
            }

            foreach (SchemaCreateScanner::createCalls($source) as $row) {
                $rows[] = ['file' => FacadeConformanceScope::displayPath($path)] + $row;
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return $rows;
    }

    /**
     * The governed population — every migration template in scope. Reported in the Pass line so a green
     * run states what it covered: "no findings" and "found nothing to look at" are different facts, and a
     * scope that silently collapsed to zero roots would otherwise read as conformance (19).
     *
     * @return list<string>
     */
    public function templates(): array
    {
        return array_values(array_filter($this->scope->files(), static::isMigrationTemplate(...)));
    }

    /**
     * A `.php.stub` under `database/migrations/` — the authored origin of a published migration, and the
     * one file in the population where a fix belongs.
     *
     * The extension is the whole test because the estate has no other shape: censused across all family
     * package roots, starters and Herd sites for ticket 30, the migration population is **154 `.php.stub`
     * templates and zero undated `.php` migrations** — every `.php` under a `database/migrations/` path
     * is dated publish output, which {@see FacadeConformanceScope::isPublishedMigration()} has already
     * dropped before this is asked.
     *
     * Note what is deliberately *not* keyed on: the **filename**. Ticket 22 specified this check against
     * `create_*` stubs and ticket 28 profiled its sweep the same way, which is exactly how the estate's
     * one live unguarded create survived both — `splicewire/tower`'s
     * `add_directory_acl_grants_and_visibility.php.stub` creates `grants` beside an `ALTER`, so its
     * filename is honest and a filename-keyed check reads it as out of population.
     */
    public static function isMigrationTemplate(string $path): bool
    {
        $path = str_replace('\\', '/', $path);

        return str_ends_with($path, '.php.stub') && str_contains($path, '/database/migrations/');
    }
}
