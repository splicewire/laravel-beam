<?php

namespace Splicewire\Beam\Tests\Doctor;

use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-facade ticket 19 — the shared scope, which is where the whole regime's correctness lives.
 * Ticket 10's census measured a naive check at ~69% false positives and every point of that came from
 * *where* a hit sat, so these are the load-bearing tests in the effort.
 *
 * Two of them are the acceptance data for questions raised by later tickets rather than by 10:
 * {@see test_a_real_vendor_directory_is_not_authorable()} is ticket 18's resolution-mode question — the
 * `~/Herd/beam-pilot-gcp-cloud-run` case, where flagging is the failure mode — and
 * {@see test_a_dated_published_migration_is_excluded_but_its_stub_source_is_not()} is the one rule that
 * fixes both the largest false-positive block and the largest false-negative gap at once.
 */
class FacadeConformanceScopeTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null && is_dir($this->root)) {
            $this->rmrf($this->root);
        }

        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        foreach ((array) glob($path.'/{,.}*', GLOB_BRACE) as $entry) {
            $entry = (string) $entry;

            if (str_ends_with($entry, '/.') || str_ends_with($entry, '/..')) {
                continue;
            }

            if (is_link($entry) || is_file($entry)) {
                @unlink($entry);
            } elseif (is_dir($entry)) {
                $this->rmrf($entry);
            }
        }

        @rmdir($path);
    }

    private function makeRoot(): string
    {
        $this->root = sys_get_temp_dir().'/beam-facade-scope-'.uniqid();
        @mkdir($this->root, 0777, true);

        return $this->root;
    }

    private function write(string $path, string $contents = "<?php\n"): string
    {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);

        return $path;
    }

    // ---- The resolution-mode check (ticket 18 §3) --------------------------------------

    /**
     * The overlay's tell. `vendor/<vendor>/<package>` as a **symlink** is live, editable source — the
     * vendored copy IS the source tree — so drift found there names a file someone can fix.
     */
    public function test_a_symlinked_vendor_package_is_authorable(): void
    {
        $base = $this->makeRoot();
        $source = $base.'/source/laravel-beam-ux';
        @mkdir($source.'/src', 0777, true);
        @mkdir($base.'/vendor/splicewire', 0777, true);
        symlink($source, $base.'/vendor/splicewire/laravel-beam-ux');

        $this->app->setBasePath($base);

        $this->assertSame(
            [realpath($base.'/vendor/splicewire/laravel-beam-ux')],
            array_map('realpath', FacadeConformanceScope::authorablePackageRoots()),
        );
    }

    /**
     * The `~/Herd/beam-pilot-gcp-cloud-run` case, and the reason this predicate exists at all. A real
     * directory under `vendor/` is git-resolved at a pinned commit: immutable, and conformant to the
     * version it pinned rather than to this one. At beam-pilot the pin predates the facade entirely, so
     * `Splicewire\Beam\Beam` exists there and its files naming it are RIGHT. Flagging them is the
     * failure mode ticket 18 named when it handed this ticket the question.
     */
    public function test_a_real_vendor_directory_is_not_authorable(): void
    {
        $base = $this->makeRoot();
        @mkdir($base.'/vendor/splicewire/laravel-beam/src', 0777, true);

        $this->app->setBasePath($base);

        $this->assertSame([], FacadeConformanceScope::authorablePackageRoots());
    }

    // ---- The cross-cutting exclusions --------------------------------------------------

    /**
     * One rule, two problems (ticket 10 §4): 58 of the census's naive hits were dated migrations that
     * are verbatim publish output — the largest false-positive block — while a default `*.php` glob
     * drops the `.php.stub` sources where a fix actually belongs, the largest false-negative gap.
     *
     * Note the exclusion keys on the **path**, never on a category surgeon reports: ticket 16 found
     * surgeon's `migration-reference` category is `--root`-relative and vanishes when the root moves
     * inside `migrations/`, so a category-keyed rule silently stops excluding.
     */
    public function test_a_dated_published_migration_is_excluded_but_its_stub_source_is_not(): void
    {
        $root = $this->makeRoot();
        $published = $this->write($root.'/database/migrations/2026_08_13_042759_create_beam_particles_table.php');
        $stub = $this->write($root.'/database/migrations/create_beam_particles_table.php.stub');

        $files = (new FacadeConformanceScope([$root]))->files();

        $this->assertNotContains(realpath($published), $files);
        $this->assertContains(realpath($stub), $files);
    }

    /**
     * A migration that carries no timestamp is a stub source living under a `.php` name (the estate has a
     * couple), not publish output — the rule is "dated", not "in a migrations directory".
     */
    public function test_an_undated_migration_php_file_is_not_treated_as_published(): void
    {
        $this->assertFalse(FacadeConformanceScope::isPublishedMigration('/pkg/database/migrations/create_beam_ranks_table.php'));
        $this->assertTrue(FacadeConformanceScope::isPublishedMigration('/pkg/database/migrations/2026_08_13_042759_create_beam_ranks_table.php'));
        $this->assertTrue(FacadeConformanceScope::isPublishedMigration('/site/database/migrations/tenant/2026_08_13_042759_x.php'));
        $this->assertFalse(FacadeConformanceScope::isPublishedMigration('/pkg/database/migrations/2026_08_13_042759_x.php.stub'));
    }

    /**
     * `tests/` is excluded by **jurisdiction, not inheritance** (10 §7). `Beam::write()` IS the real
     * write, so a test resolving the writer by hand differs only cosmetically — and including tests takes
     * the write-bypass shape from 16% to 73% false positives.
     */
    public function test_tests_and_vendor_are_pruned(): void
    {
        $root = $this->makeRoot();
        $kept = $this->write($root.'/src/Models/Thing.php');
        $test = $this->write($root.'/tests/Feature/ThingTest.php');
        $vendored = $this->write($root.'/vendor/acme/lib/src/Lib.php');

        $files = (new FacadeConformanceScope([$root]))->files();

        $this->assertContains(realpath($kept), $files);
        $this->assertNotContains(realpath($test), $files);
        $this->assertNotContains(realpath($vendored), $files);
    }

    /**
     * The owning-package exclusion, which ticket 10 §4 found is mechanically forced rather than merely
     * inherited: `BeamManager::write()`'s body is the write-bypass shape verbatim and
     * `BeamManager.php`'s docblock spells out the composed-config shape verbatim, in order to document
     * what `tableFor()` replaces. The fix flags itself twice.
     */
    public function test_beams_own_src_is_the_owning_package(): void
    {
        $this->assertTrue(FacadeConformanceScope::isOwningPackageSource(dirname(__DIR__, 2).'/src/BeamManager.php'));
        $this->assertFalse(FacadeConformanceScope::isOwningPackageSource(dirname(__DIR__, 2).'/tests/Doctor/FacadeConformanceScopeTest.php'));
        $this->assertFalse(FacadeConformanceScope::isOwningPackageSource('/somewhere/else/laravel-beam-ux/src/Models/BeamUxEntry.php'));
    }

    /** Deduplication is by realpath (07's ruling): a Herd `vendor/` symlink reaches real package source. */
    public function test_files_are_deduplicated_by_realpath(): void
    {
        $base = $this->makeRoot();
        $source = $base.'/source';
        $file = $this->write($source.'/src/Thing.php');
        symlink($source, $base.'/link');

        $files = (new FacadeConformanceScope([$source, $base.'/link']))->files();

        $this->assertSame([realpath($file)], $files);
    }
}
