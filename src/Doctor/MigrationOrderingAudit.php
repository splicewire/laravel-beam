<?php

namespace Splicewire\Beam\Doctor;

use Composer\InstalledVersions;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Install\InstallStep;
use Symfony\Component\Finder\Finder;

/**
 * Catches the cross-package migration-ordering defect from DECLARATIONS, at boot, instead of from a
 * failed `migrate` three environments later.
 *
 * Publishing stamps a migration at publish time, so install order is migration order on a greenfield
 * install. A package shipping an ALTER against a table ANOTHER package creates must register a higher
 * {@see InstallStep::$order} than the package that creates it; two packages
 * left on the default 100 are separated only by provider boot order, and the ALTER can be stamped
 * ahead of the CREATE. That is exactly how a greenfield install once came up with no
 * `silos.name_path` — beam-taxonomy created the table, tower altered it, and both sat on 100.
 *
 * Everything needed to catch it is static: the install manifest knows package → order, and each
 * package's migration stubs say which tables they create and which they alter. This audit joins them.
 *
 * Advisory only. It reads intent from source, so it is deliberately conservative — see
 * {@see tablesIn()} for what it refuses to guess about.
 */
class MigrationOrderingAudit implements DoctorAudit
{
    public function __construct(private BeamInstallManifest $manifest) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $check = 'cross-package migration ordering';

        $orders = [];
        $creates = [];
        $alters = [];

        foreach ($this->manifest->steps() as $step) {
            $path = $this->installPath($step->package);

            if ($path === null) {
                continue;
            }

            // The DECLARED order, never the position in the sorted list. Position hides the failure
            // case: two packages on the same order are separated only by registration/boot order, so a
            // tie already reads as "correctly ordered" positionally while being exactly the undeclared
            // dependency this audit exists to find.
            $orders[$step->package] = $step->order;

            foreach ($this->stubs($path) as $file) {
                [$created, $altered] = $this->tablesIn((string) file_get_contents($file));

                foreach ($created as $table) {
                    // First package to claim a create owns it, for THIS audit's purpose: an ALTER needs
                    // exactly one CREATE to be ordered against, and the earliest claim is the one an
                    // ALTER has to install after.
                    //
                    // A later duplicate is a genuinely different problem and is out of scope here — but
                    // NOT, as this comment previously said, one the migrations' own guards handle. The
                    // guard is what makes it silent: two CREATEs of one table race on filename, the
                    // loser's `hasTable` sees the winner's table and returns, and `migrate` reports
                    // success over whichever schema happened to sort first. Demonstrated 2026-08-17 in
                    // laravel-beam-starter, where a stale unguarded `beam_submissions` fork beat the
                    // canonical guarded create and built a table BeamSubmission cannot read.
                    //
                    // `$order` cannot fix that shape — both files are already ordered, one simply wins —
                    // so it needs its own check rather than an extension of this one. See
                    // `docs/agents/migration-publish-ordering.convention.md` for the boundary.
                    $creates[$table] ??= $step->package;
                }

                foreach ($altered as $table) {
                    $alters[] = [$step->package, $table, basename($file)];
                }
            }
        }

        $findings = [];

        foreach ($alters as [$package, $table, $file]) {
            $creator = $creates[$table] ?? null;

            if ($creator === null || $creator === $package) {
                // Not cross-package: either the table is created outside the manifest (the host's own
                // migrations, a third-party package) or the package alters what it also creates, which
                // `->hasMigrations([...])` already sequences.
                continue;
            }

            if ($orders[$package] > $orders[$creator]) {
                continue;
            }

            $findings[] = Finding::warn($check, sprintf(
                '%s alters [%s] in %s at install order %d, but %s creates that table at order %d. '
                .'Publishing stamps at publish time, so on a greenfield install the ALTER can be stamped '
                .'ahead of the CREATE and run against a table that does not exist yet. Equal orders are '
                .'the same defect: the tie is broken by provider boot order, which nothing declares. '
                .'Raise %s\'s install-step order above %d.',
                $package,
                $table,
                $file,
                $orders[$package],
                $creator,
                $orders[$creator],
                $package,
                $orders[$creator],
            ));
        }

        return $findings === []
            ? [Finding::pass($check, 'Every cross-package ALTER installs after the package that creates its table.')]
            : $findings;
    }

    /**
     * Tables a migration creates and alters.
     *
     * Literal single-quoted names only, on purpose. A dynamically-named target — `Beam::table('media')`,
     * `$this->target()`, a config lookup — is unresolvable without booting the package, and a guess here
     * would produce a confident wrong warning about a table that does not exist under that name. Silence
     * on those is the correct failure mode for an advisory audit: it under-reports rather than misleads.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function tablesIn(string $source): array
    {
        preg_match_all("/Schema::(?:connection\([^)]*\)->)?create\(\s*'([a-z0-9_]+)'/i", $source, $created);
        preg_match_all("/Schema::(?:connection\([^)]*\)->)?table\(\s*'([a-z0-9_]+)'/i", $source, $altered);

        return [array_values(array_unique($created[1])), array_values(array_unique($altered[1]))];
    }

    /**
     * @return list<string>
     */
    private function stubs(string $path): array
    {
        $dir = $path.'/database/migrations';

        if (! is_dir($dir)) {
            return [];
        }

        $files = [];

        foreach ((new Finder)->files()->in($dir)->name(['*.php', '*.stub']) as $file) {
            $files[] = $file->getRealPath();
        }

        return $files;
    }

    private function installPath(string $package): ?string
    {
        // The manifest labels beam-core's own step for humans ('… (core)'); the composer name is clean.
        $name = trim(preg_replace('/\s*\(.*\)$/', '', $package));

        try {
            return InstalledVersions::isInstalled($name)
                ? InstalledVersions::getInstallPath($name)
                : null;
        } catch (\OutOfBoundsException) {
            return null;
        }
    }
}
