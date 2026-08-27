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
}
