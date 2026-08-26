<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Rushing\Doctor\DoctorStatus;
use Schemastud\DataSchemas\Contracts\ServedSchemaRegistry;
use Schemastud\DataSchemas\Http\SchemaDoorMount;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\DataSchemas\Lifecycle\ServedSchemaChain;
use Splicewire\Beam\Doctor\SchemaDoorAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * {@see SchemaDoorAudit} — beam-facade ticket 102. Every case here is a state measured somewhere in the
 * estate on 2026-08-26, or one the mechanism makes reachable in a single config edit:
 *
 *  - the opt-out (4 of 15 declaring roots) and the conformant path-shaped door (10 of 15);
 *  - the path-less door mounted domain-constrained (`~/Herd/splicewire`, ticket 111);
 *  - that same door REPLACED by a bare root catch-all, which is the state ticket 111 found it in and
 *    the only defect this instrument exists to catch;
 *  - superseded copies under a dead authority (8 at `~/Herd/splicewire-app`, ticket 64's one-sided
 *    re-stem).
 *
 * Deliberately container-only — no testbench boot. The audit's whole population is a config value, a
 * route table and a directory of frozen files, and building those by hand is what lets a test declare
 * the broken route table that a booted app can no longer be talked into producing.
 */
class SchemaDoorAuditTest extends TestCase
{
    private string $servedDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->servedDir = sys_get_temp_dir().'/schema-door-audit-'.uniqid();
        @mkdir($this->servedDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->servedDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->servedDir);

        parent::tearDown();
    }

    public function test_an_opted_out_host_with_no_artifacts_is_conformant(): void
    {
        $findings = $this->audit(false)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('opts out', $findings[0]->detail);
    }

    public function test_an_undeclared_host_with_no_artifacts_is_conformant(): void
    {
        $findings = $this->audit(null)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('declares no data-schemas.base_uri', $findings[0]->detail);
    }

    public function test_an_opted_out_host_that_froze_an_artifact_anyway_is_flagged(): void
    {
        $this->freeze('https://schemas.example.test/content/article/1');

        $findings = $this->audit(false)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('unreachable', $findings[0]->detail);
    }

    public function test_a_non_absolute_authority_is_an_error_not_a_state(): void
    {
        $findings = $this->audit('/schemas')->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('names no origin', $findings[0]->detail);
    }

    public function test_a_declared_authority_with_nothing_bound_to_answer_is_flagged(): void
    {
        $audit = new SchemaDoorAudit($this->container('https://example.test/schemas', bindRegistry: false));

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString(ServedSchemaRegistry::class, $findings[0]->detail);
    }

    /** Declared, mounted, nothing frozen — a Pass that must NOT claim a probe it could not run. */
    public function test_a_declared_door_with_nothing_frozen_passes_on_the_route_table_alone(): void
    {
        $findings = $this->audit('https://example.test/schemas', mountDoor: true)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('Nothing was probed', $findings[0]->detail);
        $this->assertStringNotContainsString('GET <$id> resolves', $findings[0]->detail);
    }

    public function test_a_path_shaped_door_that_answers_its_own_frozen_id_passes(): void
    {
        $this->freeze('https://example.test/schemas/content/article/1');

        $findings = $this->audit('https://example.test/schemas', mountDoor: true)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('GET <$id> resolves', $findings[0]->detail);
    }

    public function test_a_path_less_domain_constrained_door_answers_its_own_frozen_id(): void
    {
        $this->freeze('https://schemas.example.test/content/article/1');

        $findings = $this->audit('https://schemas.example.test', mountDoor: true)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    /**
     * Ticket 111's measured defect, reproduced: a bare `GET {path}` owns the root and the door is simply
     * not in the collection. `route:list` would show this host as clean, which is why the probe asks
     * `match()` instead.
     */
    public function test_a_root_catch_all_that_swallowed_the_door_is_caught_by_the_probe(): void
    {
        $this->freeze('https://schemas.example.test/content/article/1');

        $container = $this->container('https://schemas.example.test');
        $router = $container->make('router');
        $router->get('{path}', fn () => null)->where('path', '.*')->name('site.show');
        $router->getRoutes()->refreshNameLookups();

        $findings = (new SchemaDoorAudit($container))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('does NOT reach the schema door', $findings[0]->detail);
        $this->assertStringContainsString('site.show', $findings[0]->detail);
    }

    /**
     * beam-facade 148 — the depth-dependent failure a single probe cannot see.
     *
     * A Laravel route parameter matches ONE segment unless constrained, so a door whose constraint is
     * anything narrower than `.*` serves a shallow `$id` and 404s a nested one. `ids()` is sorted, so
     * the old single probe took whichever came first alphabetically: here `.../a/1` passes the
     * two-segment constraint and `.../content/deep/nested/1` does not, and the shallow one sorts
     * first — so the old probe read this host as clean.
     *
     * This is the exact shape 148 was filed about. The constraint turned out to have been on the
     * mount since ticket 82 — the measured 404s were an origin mismatch — but the instrument could
     * only have caught it by luck, and now catches it by construction.
     */
    public function test_a_door_that_matches_only_one_segment_is_caught_by_the_deepest_id(): void
    {
        $this->freeze('https://example.test/schemas/a/1');
        $this->freeze('https://example.test/schemas/content/deep/nested/1');

        $container = $this->container('https://example.test/schemas');
        $router = $container->make('router');

        // The door, mounted with a constraint that admits exactly two segments rather than any depth.
        $router->get('schemas/{path}', fn () => null)
            ->where('path', '[^/]+/[^/]+')
            ->name(SchemaDoorAudit::ROUTE);
        $router->getRoutes()->refreshNameLookups();

        $findings = (new SchemaDoorAudit($container))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('does NOT reach the schema door', $findings[0]->detail);
        $this->assertStringContainsString('content/deep/nested/1', $findings[0]->detail);
    }

    public function test_an_unmounted_door_is_caught_before_anything_is_frozen(): void
    {
        $findings = $this->audit('https://example.test/schemas')->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('no such route', $findings[0]->detail);
    }

    public function test_a_superseded_copy_under_a_dead_authority_is_reported_as_deletable(): void
    {
        $this->freeze('https://example.test/schemas/content/article/1');
        $this->freeze('https://schemas.dead.test/content/article/1');

        $findings = $this->audit('https://example.test/schemas', mountDoor: true)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('SUPERSEDED', $findings[0]->detail);
        $this->assertStringContainsString('https://schemas.dead.test', $findings[0]->detail);
    }

    public function test_a_foreign_artifact_with_no_twin_is_reported_as_orphaned(): void
    {
        $this->freeze('https://example.test/schemas/content/article/1');
        $this->freeze('https://schemas.dead.test/content/orphan/1');

        $findings = $this->audit('https://example.test/schemas', mountDoor: true)->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('NO twin', $findings[0]->detail);
    }

    /** The two findings are independent: a broken door and a dead authority are two repairs. */
    public function test_a_broken_door_and_a_foreign_artifact_are_reported_separately(): void
    {
        $this->freeze('https://example.test/schemas/content/article/1');
        $this->freeze('https://schemas.dead.test/content/article/1');

        $findings = $this->audit('https://example.test/schemas')->run();

        $this->assertCount(2, $findings);
        $this->assertStringContainsString('does NOT reach the schema door', $findings[0]->detail);
        $this->assertStringContainsString('SUPERSEDED', $findings[1]->detail);
    }

    private function audit(mixed $baseUri, bool $mountDoor = false): SchemaDoorAudit
    {
        return new SchemaDoorAudit($this->container($baseUri, mountDoor: $mountDoor));
    }

    /**
     * A container carrying only what the audit reads: the config value, a router, and the served
     * registry the door would resolve. The door is mounted through the package's own
     * {@see SchemaDoorMount} rather than by a hand-written pattern, so a
     * change to the mounting rule fails these tests instead of silently diverging from them.
     */
    private function container(mixed $baseUri, bool $mountDoor = false, bool $bindRegistry = true): Container
    {
        $container = new Container;
        $container->instance('config', new Repository(
            $baseUri === null ? [] : ['data-schemas' => ['base_uri' => $baseUri]],
        ));

        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);

        if ($bindRegistry) {
            $container->instance(
                ServedSchemaRegistry::class,
                ServedSchemaChain::overDirectories([$this->servedDir]),
            );
        }

        if ($mountDoor) {
            $pattern = SchemaDoorMount::patternFor($baseUri);
            $domain = SchemaDoorMount::domainFor($baseUri);

            // Through the REGISTRAR when domained, exactly as the provider does it: RouteCollection
            // picks its bucket at add time, so a domain applied afterwards leaves the route filed as
            // undomained (ticket 111's trap).
            $route = $domain === null
                ? $router->get($pattern, fn () => null)
                : $router->domain($domain)->get($pattern, fn () => null);

            $route->where('path', '.*')->name(SchemaDoorAudit::ROUTE);
            $router->getRoutes()->refreshNameLookups();
        }

        return $container;
    }

    private function freeze(string $id): void
    {
        (new FilesystemSchemaRegistry($this->servedDir))->register([
            '$id' => $id,
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string']],
        ]);
    }
}
