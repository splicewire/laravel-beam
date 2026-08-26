<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Surgeon\Support\PackageOrigin;

/**
 * beam-facade ticket 140 — the two facts that make an artifact committable: which package a file belongs
 * to, and whether that package only exists in development.
 *
 * Driven over a synthetic tree with no application booted, because the class is deliberately pure: it is
 * constructed from composer's manifest and answers by string prefix, so a test needing a container would
 * be testing the wrong thing.
 */
class PackageOriginTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/beam-origin-'.bin2hex(random_bytes(6));

        foreach ([
            '/app/Http',
            '/vendor/acme/widgets/src',
            '/vendor/acme/debug-toys/src',
            '/elsewhere/splicewire/tower/src/Http',
        ] as $dir) {
            mkdir($this->root.$dir, 0777, true);
        }

        foreach ([
            '/app/Http/HostController.php',
            '/vendor/acme/widgets/src/WidgetController.php',
            '/vendor/acme/debug-toys/src/ToyController.php',
            '/elsewhere/splicewire/tower/src/Http/TowerController.php',
        ] as $file) {
            file_put_contents($this->root.$file, '<?php');
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));

        parent::tearDown();
    }

    private function origins(): PackageOrigin
    {
        return new PackageOrigin(
            $this->root.'/host',
            [
                $this->root.'/vendor/acme/widgets' => 'acme/widgets',
                $this->root.'/vendor/acme/debug-toys' => 'acme/debug-toys',
                // The shape the estate actually has: a co-dev overlay symlinks the package OUT of
                // `vendor/`, so its real path contains no `vendor/` segment at all.
                $this->root.'/elsewhere/splicewire/tower' => 'splicewire/tower',
            ],
            ['acme/debug-toys'],
        );
    }

    public function test_it_attributes_a_co_dev_linked_package_that_no_path_heuristic_could_reach(): void
    {
        $this->assertSame(
            'splicewire/tower',
            $this->origins()->packageFor($this->root.'/elsewhere/splicewire/tower/src/Http/TowerController.php'),
        );
    }

    public function test_it_attributes_a_vendor_installed_package(): void
    {
        $this->assertSame(
            'acme/widgets',
            $this->origins()->packageFor($this->root.'/vendor/acme/widgets/src/WidgetController.php'),
        );
    }

    public function test_a_file_outside_every_package_and_outside_the_host_is_unknown_not_app(): void
    {
        $this->assertSame(PackageOrigin::UNKNOWN, $this->origins()->packageFor($this->root.'/app/Http/HostController.php'));
    }

    public function test_dev_only_is_read_from_the_transitive_dev_set(): void
    {
        $origins = $this->origins();

        $this->assertTrue($origins->isDev('acme/debug-toys'));
        $this->assertFalse($origins->isDev('acme/widgets'));
        $this->assertFalse($origins->isDev(PackageOrigin::APP));
    }

    /**
     * The point of relativizing: a package resolved through a symlink and the same package resolved
     * through `vendor/` must produce the SAME row, or the artifact churns on every machine.
     */
    public function test_a_linked_package_relativizes_to_the_same_string_a_vendor_install_would(): void
    {
        $origins = $this->origins();

        $this->assertSame(
            'splicewire/tower/src/Http/TowerController.php',
            $origins->relativize($this->root.'/elsewhere/splicewire/tower/src/Http/TowerController.php'),
        );

        $this->assertSame(
            'acme/widgets/src/WidgetController.php',
            $origins->relativize($this->root.'/vendor/acme/widgets/src/WidgetController.php'),
        );
    }

    public function test_a_host_file_relativizes_against_the_host_root(): void
    {
        mkdir($this->root.'/host/app/Http', 0777, true);
        file_put_contents($this->root.'/host/app/Http/OwnController.php', '<?php');

        $origins = new PackageOrigin($this->root.'/host');

        $this->assertSame(PackageOrigin::APP, $origins->packageFor($this->root.'/host/app/Http/OwnController.php'));
        $this->assertSame('app/Http/OwnController.php', $origins->relativize($this->root.'/host/app/Http/OwnController.php'));
    }

    /**
     * A host whose manifest cannot be read still gets a resolver — degraded and honest, never absent. The
     * artifact's `unknown` bucket is what makes the degradation visible.
     */
    public function test_a_missing_composer_manifest_degrades_rather_than_throwing(): void
    {
        $origins = PackageOrigin::forBasePath($this->root.'/no-such-host');

        $this->assertSame(PackageOrigin::UNKNOWN, $origins->packageFor('/tmp/whatever.php'));
        $this->assertFalse($origins->isDev('acme/anything'));
    }
}
