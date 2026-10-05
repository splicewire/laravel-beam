<?php

namespace Splicewire\Beam\Tests\Testing;

use Splicewire\Beam\Testing\AssertsHostIaSeam;
use Splicewire\Beam\Tests\TestCase;

/**
 * T6's absent-root rule (UX-01 review): a sweep root that does not exist at this run, such as gitignored build output
 * on a fresh clone or a js-overlay link that is off, is named, and the ratchet entries under it are UNJUDGED rather
 * than stale, because absence is not a fix. A present root still judges its entries both ways.
 */
class AssertsHostIaSeamTest extends TestCase
{
    use AssertsHostIaSeam;

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
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->dir);

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

    public function test_a_published_package_copy_is_unjudged_and_the_overlay_link_is_judged(): void
    {
        // node_modules/@x/published/src: a real directory, as when the js-overlay is OFF and the tarball ships src.
        mkdir($this->dir.'/node_modules/@x/published/src', 0777, true);
        file_put_contents($this->dir.'/node_modules/@x/published/src/layout.tsx', 'Beam Starter');
        // node_modules/@x/linked -> a family checkout, as when the js-overlay is ON.
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
}
