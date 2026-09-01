<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;

/**
 * Detects **published copies of migrations a package has since retired** — the upgrade hazard that
 * publish-only stub migrations create by construction.
 *
 * A beam package ships timestamp-less `.php.stub` migrations that `vendor:publish` re-stamps into the
 * host ({@see Support\StubMigrationsAudit}). Publishing is a COPY: when the package later squashes two
 * migrations into one, or retires one outright, the host keeps the copies it already published. The
 * package has no way to reach back and remove them, and nothing warns.
 *
 * That is not hypothetical. Beam squashed the central-only and tenant-only `activity_log` migrations
 * into one `shared/` stub whose morph ids are deliberately `string` — wide enough for a bigint token id,
 * a uuid user id, AND a string tenant slug. A host still carrying the retired **tenant** copy publishes
 * `nullableUuidMorphs`, which sorts earlier, creates the columns as `uuid`, and the surviving convergent
 * stub then refuses to converge `string` onto them. Observed as 421 failures in one suite from a single
 * stale file. The convergent guard behaved correctly — it declined to report success against a shape it
 * did not declare — but the diagnosis is buried at the bottom of a migration stack trace, and the actual
 * fault (a file that should not exist) is nowhere in the message.
 *
 * ## Why a declared list rather than inference
 *
 * A retirement cannot be inferred from the package tree: a retired stub's defining property is that it is
 * *absent*, and absence is indistinguishable from "never existed" or "belongs to another package". So the
 * retiring package DECLARES what it retired and what supersedes it, and this audit reports host copies
 * that match. Matching is directory-aware — `tenant/create_activity_log_table` is retired while
 * `shared/create_activity_log_table` is canonical, and a name-only match would condemn the survivor.
 *
 * Advisory, never gating. The remedy is deleting a file from the host's own `database/migrations`, which
 * is the host's call — a package that silently deleted a host's migrations would be a worse failure mode
 * than the one this reports.
 */
class RetiredMigrationAudit implements DoctorAudit
{
    private const CHECK = 'no retired migrations left published';

    /**
     * Beam's own retirements: `<dir>/<stub base>` => what supersedes it. The key is the published
     * path with its timestamp stripped, relative to the migrations root; a bare key means the root.
     *
     * @var array<string, string>
     */
    public const BEAM_RETIRED = [
        'tenant/create_activity_log_table' => 'shared/create_activity_log_table',
        'create_central_activity_log_table' => 'shared/create_activity_log_table',

        // The media arm extraction (HTTP-03 / ADR-0178) moved the `media` table OUT of beam-core into
        // splicewire/laravel-beam-media, where it also gained the `media` → `beam_media` rename. Beam-core
        // used to ship the same DDL as a hand-duplicated flat + `tenant/` PAIR, so a host installed before
        // the extraction carries one or both. Leaving them is not cosmetic: on a rebuilt database the stale
        // copy re-creates `media`, and beam-media's stub then takes its ADOPT branch — renaming a table it
        // would otherwise have created directly — every single migrate. The superseding stub belongs to
        // another package, which is the extraction rather than a wrinkle in it.
        'create_media_table' => 'shared/create_media_table (splicewire/laravel-beam-media)',
        'tenant/create_media_table' => 'shared/create_media_table (splicewire/laravel-beam-media)',
    ];

    /** @param  array<string, string>  $retired */
    public function __construct(
        private array $retired = self::BEAM_RETIRED,
        private ?string $migrationsPath = null,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $root = $this->migrationsPath ?? database_path('migrations');

        if (! is_dir($root)) {
            return [Finding::inconclusive(self::CHECK, 'No published migrations directory — nothing to check.')];
        }

        $findings = [];

        foreach ($this->publishedKeys($root) as $key => $files) {
            if (! isset($this->retired[$key])) {
                continue;
            }

            $findings[] = Finding::warn(
                self::CHECK,
                sprintf(
                    'Retired migration still published: %s (superseded by %s). Delete it — it was squashed or '.
                    'withdrawn upstream, and a stale copy usually sorts EARLIER than its replacement, so it '.
                    'creates the table in the shape the replacement then refuses to converge onto.',
                    implode(', ', $files),
                    $this->retired[$key],
                ),
            );
        }

        return $findings !== [] ? $findings : [
            Finding::pass(self::CHECK, 'No retired migrations found in the published set.'),
        ];
    }

    /**
     * Published migrations keyed by `<dir>/<name>` with the leading timestamp stripped, so a host's
     * re-stamped copy matches the stub it came from regardless of when it was published.
     *
     * @return array<string, list<string>>
     */
    private function publishedKeys(string $root): array
    {
        $keys = [];

        foreach ($this->files($root) as $file) {
            $relative = ltrim(substr($file, strlen($root)), '/');
            $dir = str_contains($relative, '/') ? dirname($relative).'/' : '';
            $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($relative, '.php'));

            $keys[$dir.$name][] = $relative;
        }

        return $keys;
    }

    /** @return list<string> */
    private function files(string $root): array
    {
        $found = array_merge(
            (array) glob($root.'/*.php'),
            (array) glob($root.'/*/*.php'),
        );

        return array_values(array_filter($found, 'is_string'));
    }
}
