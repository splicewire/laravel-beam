<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Illuminate\Console\Command;
use Rushing\Surgeon\Audit\PackageGraph;
use Splicewire\Beam\Console\DocblockCommand;
use Splicewire\Beam\Surgeon\DocblockTierAudit;
use Splicewire\Beam\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * The WIRING control for {@see DocblockTierAudit} — pinning the one argument that decides whether the
 * audit can say anything at all.
 *
 * ⚠️ {@see DocblockTierAuditTest} is a genuine unit test of the audit's LOGIC, and it stays green under
 * the defect this file exists for: it hands `PackageGraph::fromRoots()` a synthetic tree in which BOTH
 * packages are scan roots, so its FQNs are placeable without any vendor dir. A real host is not shaped
 * that way — there is one root and the family lives in `vendor/`. Beam's two production call sites
 * (`BeamServiceProvider`'s binding and {@see DocblockCommand}) both omitted the vendor path, so
 * `PackageGraph`'s PSR-4 map held only the root's own prefix, `packageForFqn()` returned null for every
 * family FQN, and `isUpward()` — which needs a non-null target differing from the file's own package —
 * was false by construction for every reference in every file. Measured 2026-08-31 at
 * `~/Herd/splicewire-app`: 26 of 224 leading-slash see-references placeable before, 93 after.
 *
 * So the assertion here is deliberately about the GRAPH the wiring builds, not about a finding count: a
 * finding count is a fact about the host's docblocks (the flagship legitimately has zero — the app is
 * the top tier and reaches everything it references), while "can this graph place a vendored family
 * class" is the capability that was missing.
 */
class DocblockTierAuditWiringTest extends TestCase
{
    /**
     * The provider binding. `Rushing\Surgeon\Audit\PackageGraph` is a real vendored family class in this
     * package's own `vendor/` (which the testbench skeleton symlinks), so it resolves to
     * `rushing/laravel-surgeon` with the vendor path passed and to NULL without it.
     */
    public function test_the_container_binding_builds_a_graph_that_can_place_a_vendored_family_class(): void
    {
        $audit = $this->app->make(DocblockTierAudit::class);

        $graph = (new \ReflectionProperty(DocblockTierAudit::class, 'graph'))->getValue($audit);

        $this->assertInstanceOf(PackageGraph::class, $graph);
        $this->assertSame('rushing/laravel-surgeon', $graph->packageForFqn(PackageGraph::class));
    }

    /** The console call site, which carried the identical omission. */
    public function test_the_console_command_builds_a_graph_that_can_place_a_vendored_family_class(): void
    {
        $base = $this->app->basePath();

        $command = new class extends DocblockCommand
        {
            /** @param  list<string>  $roots */
            public function graphFor(array $roots): PackageGraph
            {
                return $this->buildGraph($roots);
            }
        };
        $command->setLaravel($this->app);

        // Bind an empty option bag so `--base` reads as unset and `baseDir()` falls through to the app
        // base path — without running `handle()`, whose whole-tree scan is not what is under test.
        $input = new ArrayInput([], $command->getDefinition());
        (new \ReflectionProperty(Command::class, 'input'))->setValue($command, $input);

        $graph = $command->graphFor([$base]);

        $this->assertSame('rushing/laravel-surgeon', $graph->packageForFqn(PackageGraph::class));
    }
}
