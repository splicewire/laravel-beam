<?php

namespace Splicewire\Beam\Install;

use Rushing\SchemaConvergence\ConvergentTable;

/**
 * One published beam migration racing a third-party migration of the SAME name.
 *
 * The unit is the **filename stem** — `create_activity_log_table` — not the table, because a table name
 * is frequently dynamic (`Beam::table('particles')`, `config('activitylog.table_name')`) and a
 * stem is not. Two files whose stem matches are two packages that both believe they create the same
 * thing; that is the collision family this map sighted four times (13's Lunar `roles.id`, 16's two
 * `create_media_table`s, 20's submissions fork, and Lunar's `activity_log`).
 *
 * "Ours" is always a file under `database/migrations/**` — a published copy the host owns and we may
 * re-date. "Theirs" is always a file a package registers from its own source (`loadMigrationsFrom`),
 * which we may not touch. That asymmetry is the whole reason filename order is the only lever here:
 * {@see ConvergentTable} dissolves every collision *inside* the family because
 * both sides converge, and it cannot reach a guard we do not own.
 *
 * @see TableOwnershipResolver
 */
final class MigrationCollision
{
    /**
     * @param  string  $stem  the filename with its date prefix stripped, e.g. `create_activity_log_table`
     * @param  string  $ourFile  absolute path to the published copy
     * @param  string  $ourPrefix  its sortable date prefix, e.g. `2026_08_14_023918`
     * @param  list<string>  $theirFiles  absolute paths to every competing copy
     * @param  string  $theirPrefix  the EARLIEST competing prefix — the one that has to be beaten
     */
    public function __construct(
        public readonly string $stem,
        public readonly string $ourFile,
        public readonly string $ourPrefix,
        public readonly array $theirFiles,
        public readonly string $theirPrefix,
    ) {}

    /**
     * Whether beam's copy already sorts first. Migrations run in ascending filename order, so the
     * comparison is a plain string compare on the prefix — exactly what the migrator does.
     */
    public function beamWins(): bool
    {
        return $this->ourPrefix < $this->theirPrefix;
    }

    /**
     * A human label for the package the competing file belongs to, derived from its path
     * (`…/vendor/lunarphp/core/database/migrations/…` ⇒ `lunarphp/core`). Falls back to the directory
     * the file sits in when the path has no `vendor/` segment (a path-repository package in dev).
     */
    public function competitor(): string
    {
        $parts = explode(DIRECTORY_SEPARATOR, $this->theirFiles[0]);
        $at = array_search('vendor', $parts, true);

        if ($at !== false && isset($parts[$at + 2])) {
            return $parts[$at + 1].'/'.$parts[$at + 2];
        }

        return dirname($this->theirFiles[0]);
    }

    /** The migration NAME the migrator records in the `migrations` table (filename, no extension). */
    public function ourMigrationName(): string
    {
        return basename($this->ourFile, '.php');
    }
}
