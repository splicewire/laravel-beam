<?php

namespace Splicewire\Beam\Tests\Testing;

use Illuminate\Filesystem\Filesystem;
use Splicewire\Beam\Testing\AssertsHostIaSeam;
use Splicewire\Beam\Tests\TestCase;

/**
 * T6's absent-root rule (UX-01 review): a sweep root that does not exist at this run, such as gitignored build output
 * on a fresh clone or a js-overlay link that is off, is named, and the ratchet entries under it are UNJUDGED rather
 * than stale, because absence is not a fix. A present root still judges its entries both ways.
 */
class AssertsHostIaSeamTest extends TestCase
{
    use AssertsHostIaSeam {
        hostIaScopedLiterals as traitScopedLiterals;
    }

    private string $dir;

    /** @var array{source: list<string>, built: list<string>} */
    private array $roots;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/host-ia-seam-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/src', 0777, true);
        file_put_contents($this->dir.'/src/header.tsx', '<a href="/operator">Operator</a>');
        file_put_contents($this->dir.'/src/clean.tsx', 'export const ok = true;');

        $this->roots = ['source' => [$this->dir.'/src', $this->dir.'/overlay-off'], 'built' => [$this->dir.'/build']];
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);

        parent::tearDown();
    }

    protected function hostIaRatchet(): array
    {
        return [];
    }

    protected function hostIaPlays(): array
    {
        return [];
    }

    protected function hostIaSweepRoots(): array
    {
        return $this->roots;
    }

    public function test_an_absent_built_root_is_named_and_its_entries_are_unjudged_not_stale(): void
    {
        $found = $this->hostIaT6();

        $this->assertSame(["T6 source {$this->dir}/src/header.tsx href=\"/operator\""], array_keys($found));

        $diff = $this->hostIaRatchetDiff($found, [
            "T6 source {$this->dir}/src/header.tsx href=\"/operator\"" => 'UX-12a',
            "T6 built {$this->dir}/build Beam Starter" => 'UX-03',
            "T6 source {$this->dir}/overlay-off/layouts/site-layout.tsx Beam Starter" => 'UX-03',
        ]);

        $this->assertSame([], $diff['unlisted']);
        $this->assertSame([], $diff['stale'], 'an entry under an absent root must not read as fixed');
        $this->assertSame([
            "T6 built {$this->dir}/build Beam Starter",
            "T6 source {$this->dir}/overlay-off/layouts/site-layout.tsx Beam Starter",
        ], array_keys($diff['unjudged']));
    }

    public function test_a_present_root_still_judges_both_ways(): void
    {
        $found = $this->hostIaT6();

        $diff = $this->hostIaRatchetDiff($found, [
            "T6 source {$this->dir}/src/clean.tsx Beam Starter" => 'UX-03',
        ]);

        $this->assertSame(["T6 source {$this->dir}/src/header.tsx href=\"/operator\""], array_keys($diff['unlisted']));
        $this->assertSame(["T6 source {$this->dir}/src/clean.tsx Beam Starter"], array_keys($diff['stale']));
        $this->assertSame([], $diff['unjudged']);
    }

    public function test_a_pnpm_installed_package_is_unjudged_and_the_overlay_link_is_judged(): void
    {
        // node_modules/@x/published -> node_modules/.pnpm/…: a pnpm install of the published tarball (which ships src),
        // as when the js-overlay is OFF. It is a symlink too, so only where it resolves tells it from the overlay.
        $store = $this->dir.'/node_modules/.pnpm/@x+published@0.1.3/node_modules/@x/published';
        mkdir($store.'/src', 0777, true);
        file_put_contents($store.'/src/layout.tsx', 'Beam Starter');
        mkdir($this->dir.'/node_modules/@x', 0777, true);
        symlink($store, $this->dir.'/node_modules/@x/published');
        // node_modules/@x/linked -> a family checkout outside any node_modules, as when the js-overlay is ON.
        mkdir($this->dir.'/checkout/src', 0777, true);
        file_put_contents($this->dir.'/checkout/src/layout.tsx', 'Beam Starter');
        symlink($this->dir.'/checkout', $this->dir.'/node_modules/@x/linked');

        $published = $this->dir.'/node_modules/@x/published/src';
        $linked = $this->dir.'/node_modules/@x/linked/src';
        $this->roots = ['source' => [$published, $linked], 'built' => []];

        $found = $this->hostIaT6();
        $this->assertSame(["T6 source {$linked}/layout.tsx Beam Starter"], array_keys($found));

        $diff = $this->hostIaRatchetDiff($found, [
            "T6 source {$linked}/layout.tsx Beam Starter" => 'UX-03',
            "T6 source {$published}/other.tsx Beam Starter" => 'UX-03',
        ]);
        $this->assertSame([], $diff['unlisted']);
        $this->assertSame([], $diff['stale']);
        $this->assertSame(["T6 source {$published}/other.tsx Beam Starter"], array_keys($diff['unjudged']));
    }

    public function test_tests_stories_and_prototypes_are_not_product_source(): void
    {
        mkdir($this->dir.'/src/_prototype', 0777, true);
        file_put_contents($this->dir.'/src/page.test.tsx', 'Beam Starter');
        file_put_contents($this->dir.'/src/page.stories.tsx', 'Beam Starter');
        file_put_contents($this->dir.'/src/_prototype/demo.tsx', 'Beam Starter');
        file_put_contents($this->dir.'/src/tour.tsx', 'Beam Starter');
        $this->roots = ['source' => [$this->dir.'/src'], 'built' => []];

        $keys = array_keys($this->hostIaT6());

        $this->assertContains("T6 source {$this->dir}/src/tour.tsx Beam Starter", $keys);
        $this->assertSame([], array_values(array_filter($keys, fn ($k) => str_contains($k, '.test.') || str_contains($k, '.stories.') || str_contains($k, '_prototype'))));
    }

    public function test_a_scoped_literal_is_judged_only_under_its_roots(): void
    {
        file_put_contents($this->dir.'/src/tour.tsx', 'food-safety');
        $this->roots = ['source' => [$this->dir.'/src'], 'built' => []];

        $this->assertSame([], array_values(array_filter(array_keys($this->hostIaT6()), fn ($k) => str_contains($k, 'food-safety'))), 'food-safety is not judged outside its scoped roots');
        $this->assertContains('food-safety', $this->hostIaScopedLiteralsFor('ui/src'));
        $this->assertNotContains('food-safety', $this->hostIaScopedLiteralsFor('app'));
    }

    public function test_a_literal_scoped_to_a_subpath_is_judged_only_under_that_subpath(): void
    {
        // app-walkthrough APP-01: `'href' =>` is judged over app/Navigation only (APP-1), not every app/ file.
        mkdir($this->dir.'/src/Navigation', 0777, true);
        file_put_contents($this->dir.'/src/Navigation/AppNavigation.php', "<?php return ['href' => '/x'];");
        file_put_contents($this->dir.'/src/Surfaces.php', "<?php return ['href' => '/y'];");
        $this->roots = ['source' => [$this->dir.'/src'], 'built' => []];
        $this->scoped = ["'href' =>" => [$this->dir.'/src/Navigation']];

        $keys = array_values(array_filter(array_keys($this->hostIaT6()), fn ($k) => str_contains($k, "'href' =>")));

        $this->assertSame(["T6 source {$this->dir}/src/Navigation/AppNavigation.php 'href' =>"], $keys);
    }

    /** @var array<string, list<string>>|null */
    private ?array $scoped = null;

    protected function hostIaScopedLiterals(): array
    {
        return $this->scoped ?? $this->traitScopedLiterals();
    }

    private function hostIaScopedLiteralsFor(string $root): array
    {
        return array_keys(array_filter($this->hostIaScopedLiterals(), fn (array $scope) => in_array($root, $scope, true)));
    }
}
