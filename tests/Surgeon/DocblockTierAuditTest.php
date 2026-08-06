<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Surgeon\Audit\PackageGraph;
use Splicewire\Beam\Surgeon\DocblockTierAudit;

/**
 * THE POLICY find-half (`DocblockTierAudit`) — the "no up-tier {@see}" tier audit relocated DOWN from
 * surgeon into beam. It scans a package's docblocks for an importable leading-slash `@see` naming a
 * HIGHER-tier class (the form Pint's `fully_qualified_strict_types` would forge into an illegal upward
 * `use` import) and nominates the surgeon-resident deref MECHANISM to fix it — the legal downward edge.
 *
 * Pure unit test (no Laravel boot): stand up a two-package tree — a lower-tier `acme/beam`
 * (`Acme\Beam\ => src/`) depending on nothing, and a higher-tier `acme/app` (`App\ => app/`) requiring
 * beam — and assert the audit places the tiers via {@see PackageGraph} and emits the exact splice payload.
 */
class DocblockTierAuditTest extends TestCase
{
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->rrmdir($root);
        }
        $this->roots = [];

        parent::tearDown();
    }

    /**
     * @return array{0:string, 1:string} [root, beamFile]
     */
    private function twoPackageTree(string $see): array
    {
        $root = $this->tmp('docblock');
        $this->roots[] = $root;

        $this->write($root.'/beam/composer.json', json_encode([
            'name' => 'acme/beam',
            'autoload' => ['psr-4' => ['Acme\\Beam\\' => 'src/']],
        ], JSON_PRETTY_PRINT));

        $this->write($root.'/app/composer.json', json_encode([
            'name' => 'acme/app',
            'require' => ['acme/beam' => '*'],
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_PRETTY_PRINT));

        $beamFile = $root.'/beam/src/Particle.php';
        $this->write($beamFile, <<<PHP
            <?php

            namespace Acme\\Beam;

            /**
             * A beam particle. See {$see} for the app-tier binding.
             */
            class Particle
            {
            }

            PHP);

        return [$root, $beamFile];
    }

    public function test_it_flags_an_upward_see_and_emits_the_exact_deref_payload(): void
    {
        [$root, $beamFile] = $this->twoPackageTree('{@see \\App\\Providers\\ParticleServiceProvider}');
        $graph = PackageGraph::fromRoots([$root.'/beam', $root.'/app']);

        $findings = (new DocblockTierAudit([$beamFile], 'acme/beam', $graph))->suggestOperations();

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->isFixable());
        $this->assertSame('docblock-deref', $findings[0]->suggestion->kind);
        $this->assertSame(
            '{@see \\App\\Providers\\ParticleServiceProvider}',
            $findings[0]->suggestion->payload['old'],
        );
        $this->assertSame('`ParticleServiceProvider`', $findings[0]->suggestion->payload['new']);
    }

    public function test_it_also_flags_the_bare_see_annotation_form(): void
    {
        [$root, $beamFile] = $this->twoPackageTree('@see \\App\\Providers\\ParticleServiceProvider');
        $graph = PackageGraph::fromRoots([$root.'/beam', $root.'/app']);

        $findings = (new DocblockTierAudit([$beamFile], 'acme/beam', $graph))->suggestOperations();

        $this->assertCount(1, $findings);
        $this->assertSame(
            '@see \\App\\Providers\\ParticleServiceProvider',
            $findings[0]->suggestion->payload['old'],
        );
        $this->assertSame('`ParticleServiceProvider`', $findings[0]->suggestion->payload['new']);
    }

    public function test_it_does_not_flag_a_downward_see(): void
    {
        // app references beam — a legal downward @see. beam does NOT require app back.
        $root = $this->tmp('docblock-down');
        $this->roots[] = $root;

        $this->write($root.'/beam/composer.json', json_encode([
            'name' => 'acme/beam', 'autoload' => ['psr-4' => ['Acme\\Beam\\' => 'src/']],
        ], JSON_PRETTY_PRINT));
        $this->write($root.'/app/composer.json', json_encode([
            'name' => 'acme/app', 'require' => ['acme/beam' => '*'], 'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_PRETTY_PRINT));
        $appFile = $root.'/app/app/Consumer.php';
        $this->write($appFile, <<<'PHP'
            <?php

            namespace App;

            /** Consumes {@see \Acme\Beam\Particle}. */
            class Consumer
            {
            }

            PHP);

        $graph = PackageGraph::fromRoots([$root.'/beam', $root.'/app']);
        $findings = (new DocblockTierAudit([$appFile], 'acme/app', $graph))->suggestOperations();

        $this->assertSame([], $findings);
    }

    private function tmp(string $label): string
    {
        $dir = sys_get_temp_dir().'/beam-docblock-'.$label.'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);

        return $dir;
    }

    private function write(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $contents);
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
