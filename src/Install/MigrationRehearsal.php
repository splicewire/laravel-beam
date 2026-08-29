<?php

namespace Splicewire\Beam\Install;

use Illuminate\Database\Migrations\Migration;
use Rushing\SchemaConvergence\ConvergentTable;
use Splicewire\Beam\Doctor\PackageStubConflictAudit;
use Throwable;

/**
 * Ask ONE migration body what it would do against the live database, without publishing it and without
 * writing anything (beam-facade ticket 182).
 *
 * Extracted from {@see ConvergencePreflight::rehearseOne()} because a second instrument now needs the
 * identical act over a DIFFERENT population: the preflight rehearses the files one `migrate` is about to
 * run, and {@see PackageStubConflictAudit} rehearses the `.php.stub` templates a package *declares* —
 * files that are not published into this host at all, and in the interesting case never will be, because
 * a host override already occupies their stem. Ticket 146 (the report-only `what would collide if I
 * published?` command) is reduced to a command wrapper plus a renderer over this class; its scope names
 * this same missing capability and it must consume this rather than build a second copy.
 *
 * ## An unpublished stub is rehearsable for a mechanical reason, not a lucky one
 *
 * The file is **copied to a temp `.php` path before it is required**, which the preflight already did for
 * a different reason — PHP's `require` returns `true` rather than the migration object on a second
 * include of the same path, and `migrate` runs in this same process a few lines later. The side effect is
 * the capability: a migration is an anonymous class with no `__DIR__` dependency and no namespace, so
 * WHERE it is required from is irrelevant, and a `.php.stub` under a package's `database/migrations/`
 * resolves exactly as its published copy would. Nothing here strips a placeholder, because the estate's
 * migration stubs carry none — a `.php.stub` in this family is a publishable file verbatim, re-stamped
 * on publish by spatie/laravel-package-tools and otherwise copied byte for byte.
 *
 * ## What the caller still owes
 *
 * {@see RehearsalSafety} decides whether a body may be rehearsed at all, and this class asks it first. A
 * body it refuses is returned as a SKIP with the reason, never run — `ConvergentTable::rehearse()`
 * neutralises convergent terminals and NOTHING ELSE, so a `DB::statement()` beside a guard would execute
 * for real during a pass that promised to write nothing. **Do not widen that predicate to raise this
 * audit's coverage.** The caller also owns the POPULATION: this class rehearses whatever file it is
 * handed and never globs, because the two callers scope oppositely on purpose.
 *
 * A throw is a result, not a crash. A stub whose `up()` reaches a class, config key or connection this
 * host does not have is skipped and SAID, for the same reason the preflight does it: the instrument runs
 * before anything is committed to, and an instrument that dies on the first unusual body reports nothing
 * about the rest.
 */
class MigrationRehearsal
{
    /**
     * Rehearse one migration file — published copy or package stub — and report what its convergent
     * guards would do.
     *
     * @param  string  $migration  the name to report it under (the migrator's name for a published copy;
     *                             the stem for a stub, which has no stamp yet)
     * @param  string  $file  absolute path to a `.php` or `.php.stub` migration body
     * @param  string|null  $source  its contents, when the caller has already read them
     */
    public static function of(string $migration, string $file, ?string $source = null): RehearsedMigration
    {
        $source ??= (string) @file_get_contents($file);

        $named = static::declaredClassIn($source);

        if ($named !== null) {
            return RehearsedMigration::skip($migration, $file, sprintf(
                'declares the NAMED migration class `%s` — including it would leave that class defined '.
                'for the rest of this process, and whatever loads it next (the migrator itself, or a '.
                'second instrument) dies on `Cannot redeclare class`, a PHP fatal no `catch` can intercept',
                $named,
            ));
        }

        $unsafe = RehearsalSafety::explain($source);

        if ($unsafe !== null) {
            return RehearsedMigration::skip($migration, $file, $unsafe);
        }

        $copy = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beam-rehearsal-'.bin2hex(random_bytes(8)).'.php';

        if (! @copy($file, $copy)) {
            return RehearsedMigration::skip($migration, $file, 'could not be copied to a temp path to be read');
        }

        try {
            $instance = require $copy;

            if (! $instance instanceof Migration) {
                return RehearsedMigration::skip($migration, $file, 'does not return a Migration instance');
            }

            return new RehearsedMigration(
                $migration,
                $file,
                ConvergentTable::rehearse(fn () => $instance->up()),
            );
        } catch (Throwable $e) {
            return RehearsedMigration::skip($migration, $file, 'could not be rehearsed: '.$e->getMessage());
        } finally {
            @unlink($copy);
        }
    }

    /**
     * The top-level NAMED migration class this body declares, or null for the anonymous
     * `return new class extends Migration` the estate writes almost everywhere.
     *
     * ## Why a named class is refused OUTRIGHT rather than merely deduplicated
     *
     * Every rehearsal copies to a **fresh** temp path, deliberately: `require` returns `true` rather than
     * the migration object on a second include of the same path, and `migrate` runs in this same process
     * moments later. That is a complete defence for an anonymous class, which has no name to collide. It
     * is **no defence at all** for a named one — the include declares the class globally, two distinct
     * temp paths defeat PHP's own include-once protection, and the next thing to load that class dies on
     * `Cannot redeclare class`. That is a **fatal error, not a Throwable**, so the `catch` below cannot
     * see it and the command dies with no output at all.
     *
     * **The poisoning outlives the rehearsal, which is why refusing late is not enough.** Two different
     * victims have been measured, and only the first is inside this class:
     *
     * - a **second instrument** rehearsing the same file — measured 2026-08-29 (beam-facade 187), when a
     *   second audit joined `PackageStubConflictAudit` and `splicewire:beam:doctor` at
     *   `~/Herd/splicewire-app` exited **255 with zero findings**;
     * - **`migrate` itself**, a few lines after the preflight that rehearsed the pending file — measured
     *   2026-08-27 (beam-docs-satellite 25), when `splicewire:tower:install` exited **255** on a fresh
     *   database. Nothing in this class is on that second path, so a guard that only refused an
     *   already-loaded class would not have helped it at all.
     *
     * `RehearsalSafety::WRITES` screens three hazards — a Schema write outside a guard, a raw statement,
     * a row write — and **has no concept of a class declaration**, so this shape is invisible to it by
     * construction. It is screened here instead, where the `require` actually happens.
     *
     * **Coverage cost, measured rather than assumed: zero.** The estate declares exactly two convergent
     * class-named migrations family-wide, both in `splicewire/laravel-beam-tenancy`
     * (`create_domains_table`, `create_tenants_table`), and both were *already* being skipped — one for
     * returning no Migration instance, the other on a `RehearsalSafety` refusal. The flagship rehearses
     * the same 150 of 182 declarations with this guard as without it. What changes is that `domains` is
     * now skipped **before** the include rather than after it, which is the whole repair: the old skip
     * was not side-effect-free.
     *
     * The durable fix upstream is to rewrite those two stubs in the anonymous shape every sibling uses.
     * This guard is what makes the estate safe while they are not, and it stays afterwards, because the
     * next such stub will be written by someone who does not know this.
     */
    protected static function declaredClassIn(string $source): ?string
    {
        if (preg_match('/^\s*(?:abstract\s+)?class\s+(\w+)\s+extends\s+/mi', $source, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
