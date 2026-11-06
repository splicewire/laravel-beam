<?php

namespace Splicewire\Beam\Tests\Codegen;

use Illuminate\Filesystem\Filesystem;
use LogicException;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Codegen\PackageTypeRoots;

/**
 * A fixture host: `vendor/composer/installed.json` plus each package's live `composer.json`, written per test
 * under a private temporary root so no test reads another's packages.
 */
class PackageTypeRootsTest extends TestCase
{
    private string $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = sys_get_temp_dir().'/beam-package-type-roots-'.uniqid();
        (new Filesystem)->ensureDirectoryExists($this->host);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->host);
        parent::tearDown();
    }

    public function test_directories_order_by_package_name_then_declared_order(): void
    {
        $this->package('zeta/pkg', ['src/Second' => 'second', 'src/First' => 'first']);
        $this->package('alpha/pkg', ['src/Data' => 'alpha data']);
        $this->installed(['zeta/pkg', 'alpha/pkg']);

        $roots = PackageTypeRoots::forHost($this->host);

        $this->assertSame([
            $this->host.'/vendor/alpha/pkg/src/Data',
            $this->host.'/vendor/zeta/pkg/src/Second',
            $this->host.'/vendor/zeta/pkg/src/First',
        ], $roots->directories());
        $this->assertSame([
            'alpha/pkg' => [$this->host.'/vendor/alpha/pkg/src/Data' => 'alpha data'],
            'zeta/pkg' => [
                $this->host.'/vendor/zeta/pkg/src/Second' => 'second',
                $this->host.'/vendor/zeta/pkg/src/First' => 'first',
            ],
        ], $roots->declared());
    }

    public function test_the_live_composer_json_wins_over_installed_json_copy_of_extra(): void
    {
        $this->package('acme/pkg', ['src/Live' => 'what the checkout declares now'], extraDirs: ['src/Stale']);
        $this->installed(['acme/pkg'], staleExtra: ['acme/pkg' => ['src/Stale' => 'what composer recorded at install']]);

        $this->assertSame([$this->host.'/vendor/acme/pkg/src/Live'], PackageTypeRoots::forHost($this->host)->directories());
    }

    public function test_a_host_without_installed_json_has_no_roots(): void
    {
        $this->package('acme/pkg', ['src/Data' => 'never read without composer\'s record']);

        $roots = PackageTypeRoots::forHost($this->host);

        $this->assertSame([], $roots->directories());
        $this->assertSame([], $roots->declared());
    }

    public function test_packages_without_a_declaration_or_a_manifest_contribute_nothing(): void
    {
        $this->package('acme/silent', null);
        $this->installed(['acme/silent', 'acme/metapackage']);

        $this->assertSame([], PackageTypeRoots::forHost($this->host)->directories());
    }

    public function test_the_hosts_root_package_is_never_read(): void
    {
        (new Filesystem)->ensureDirectoryExists($this->host.'/app/Data');
        file_put_contents($this->host.'/composer.json', json_encode([
            'name' => 'acme/host',
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
            'extra' => ['typescript-transformer' => ['directories' => ['app/Data' => 'the host lists its own roots']]],
        ]));
        $this->installed([]);

        $this->assertSame([], PackageTypeRoots::forHost($this->host)->directories());
    }

    public function test_a_co_dev_symlink_stays_at_its_vendor_path(): void
    {
        $checkout = $this->host.'/checkouts/pkg';
        $this->writePackage($checkout, 'acme/linked', ['src/Data' => 'linked']);
        (new Filesystem)->ensureDirectoryExists($this->host.'/vendor/acme');
        symlink($checkout, $this->host.'/vendor/acme/linked');
        $this->installed(['acme/linked']);

        $this->assertSame([$this->host.'/vendor/acme/linked/src/Data'], PackageTypeRoots::forHost($this->host)->directories());
    }

    public function test_an_absent_directory_fails_naming_the_package_and_directory(): void
    {
        $this->package('acme/pkg', ['src/Missing' => 'declared but never created'], create: false);
        $this->installed(['acme/pkg']);

        $this->expectFailure('acme/pkg', 'src/Missing');
    }

    public function test_a_directory_outside_the_psr4_roots_fails(): void
    {
        $this->package('acme/pkg', ['resources/content' => 'MDX pages are never autoloaded PHP']);
        $this->installed(['acme/pkg']);

        $this->expectFailure('acme/pkg', 'resources/content');
    }

    public function test_a_directory_escaping_the_package_root_fails(): void
    {
        $this->package('acme/pkg', ['src/../../other/src' => 'reaches a sibling'], create: false);
        (new Filesystem)->ensureDirectoryExists($this->host.'/vendor/acme/other/src');
        $this->installed(['acme/pkg']);

        $this->expectFailure('acme/pkg', 'src/../../other/src');
    }

    public function test_a_reason_that_is_not_a_non_empty_string_fails(): void
    {
        foreach (['', '   ', 42, null, ['why']] as $reason) {
            $this->package('acme/pkg', ['src/Data' => $reason]);
            $this->installed(['acme/pkg']);

            try {
                PackageTypeRoots::forHost($this->host);
                $this->fail('accepted reason '.json_encode($reason));
            } catch (LogicException $e) {
                $this->assertStringContainsString('acme/pkg', $e->getMessage());
                $this->assertStringContainsString('src/Data', $e->getMessage());
            }
        }
    }

    public function test_a_declaration_that_is_not_a_directory_to_reason_map_fails(): void
    {
        $this->writePackage($this->host.'/vendor/acme/pkg', 'acme/pkg', null);
        $composer = json_decode((string) file_get_contents($this->host.'/vendor/acme/pkg/composer.json'), true);
        $composer['extra'] = [PackageTypeRoots::EXTRA_KEY => ['directories' => ['src/Data']]];
        file_put_contents($this->host.'/vendor/acme/pkg/composer.json', json_encode($composer));
        $this->installed(['acme/pkg']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('acme/pkg');
        PackageTypeRoots::forHost($this->host);
    }

    private function expectFailure(string $package, string $directory): void
    {
        try {
            PackageTypeRoots::forHost($this->host);
        } catch (LogicException $e) {
            $this->assertStringContainsString($package, $e->getMessage());
            $this->assertStringContainsString($directory, $e->getMessage());

            return;
        }

        $this->fail("no LogicException for {$package} {$directory}");
    }

    /** @param  array<string, mixed>|null  $directories  null writes a composer.json with no declaration */
    private function package(string $name, ?array $directories, bool $create = true, array $extraDirs = []): void
    {
        $root = $this->host.'/vendor/'.$name;
        $this->writePackage($root, $name, $directories, $create);
        foreach ($extraDirs as $dir) {
            (new Filesystem)->ensureDirectoryExists($root.'/'.$dir);
        }
    }

    /** @param  array<string, mixed>|null  $directories */
    private function writePackage(string $root, string $name, ?array $directories, bool $create = true): void
    {
        (new Filesystem)->ensureDirectoryExists($root.'/src');
        $composer = ['name' => $name, 'autoload' => ['psr-4' => ['Acme\\' => 'src/']]];
        if ($directories !== null) {
            $composer['extra'] = [PackageTypeRoots::EXTRA_KEY => ['directories' => $directories]];
            foreach (array_keys($directories) as $dir) {
                if ($create) {
                    (new Filesystem)->ensureDirectoryExists($root.'/'.$dir);
                }
            }
        }
        file_put_contents($root.'/composer.json', json_encode($composer));
    }

    /**
     * composer v2's shape: install-path relative to vendor/composer/.
     *
     * @param  list<string>  $packages
     * @param  array<string, array<string, string>>  $staleExtra
     */
    private function installed(array $packages, array $staleExtra = []): void
    {
        (new Filesystem)->ensureDirectoryExists($this->host.'/vendor/composer');
        $entries = array_map(fn (string $name) => array_filter([
            'name' => $name,
            'install-path' => '../'.$name,
            'extra' => isset($staleExtra[$name]) ? [PackageTypeRoots::EXTRA_KEY => ['directories' => $staleExtra[$name]]] : null,
        ]), $packages);
        file_put_contents($this->host.'/vendor/composer/installed.json', json_encode(['packages' => $entries]));
    }
}
