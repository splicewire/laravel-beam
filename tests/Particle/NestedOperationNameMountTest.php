<?php

namespace Splicewire\Beam\Tests\Particle;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RouteInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Mount\ParticleMounter;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Particle\Subject\NoSubject;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Tests\TestCase;

/**
 * graph-operations 03: **an operation's name can nest, and subject-free operations mount above `{id}`.**
 *
 * Two mount rules, for every resource:
 *
 * - a dot in an operation's name is a path separator in the URL only (`graph.neighbours` →
 *   `…/graph/neighbours`; the route name, registry key and permission name keep the dot);
 * - `Particle::mount(…)->ops(…)` routes a subject-free operation above the resource's own routes, so
 *   `GET {uri}/graph` is not read as a record whose id is "graph".
 *
 * Plus the registry guard the nesting makes necessary: two registrations sharing a flat key under
 * different splits (`a.b`+`c` against `a`+`b.c`) are refused, naming both.
 */
class NestedOperationNameMountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('yards', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        DB::table('yards')->insert(['id' => 1, 'name' => 'north']);

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'yards',
            backing: NestedYard::class,
        ));
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────

    private function op(string $resource, string $name, mixed $subject = null, ?Closure $handle = null, ?HttpMethod $method = null): ParticleOperation
    {
        return new ParticleOperation(
            resource: $resource,
            name: $name,
            kind: OperationKind::Read,
            handle: $handle ?? fn ($subject) => ['data' => ['op' => $name, 'subject' => $subject?->getKey()]],
            subject: $subject,
            method: $method ?? HttpMethod::Get,
        );
    }

    private function register(ParticleOperation $operation): ParticleOperation
    {
        $this->app->make(ParticleOperationRegistry::class)->register($operation);

        return $operation;
    }

    /** @return list<string> every route name for `yards`, in route-table (= matching) order */
    private function order(): array
    {
        Route::getRoutes()->refreshNameLookups();

        $names = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RouteInstance $route */
            $name = $route->getName();

            if (is_string($name) && str_starts_with($name, 'yards.') && ! str_starts_with($name, 'yards.hooks')) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function uriOf(string $name): ?string
    {
        Route::getRoutes()->refreshNameLookups();

        return Route::getRoutes()->getByName($name)?->uri();
    }

    // ── 1. A dot nests the URL and nothing else ──────────────────────────────────────────────────────

    public function test_a_dotted_name_on_a_record_is_served_at_a_nested_path_and_keeps_the_dot_in_its_name(): void
    {
        $this->register($this->op('yards', 'a.b'));

        Particle::mount('yards')->ops(['a.b']);

        $this->assertSame('yards/{id}/a/b', $this->uriOf('yards.a.b'));
        $this->assertNotNull($this->app->make(ParticleOperationRegistry::class)->find('yards', 'a.b'));

        $this->getJson('/yards/1/a/b')->assertOk()->assertJson(['data' => ['op' => 'a.b', 'subject' => 1]]);
    }

    public function test_a_slash_in_an_operation_name_is_refused(): void
    {
        // At registration: a slash is outside the registry key charset, so such an op never registers.
        try {
            $this->register($this->op('yards', 'a/b'));
            $this->fail('an operation named [a/b] was registered');
        } catch (\Throwable $refused) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $refused);
        }

        // At the mount, for any name that reaches it some other way, naming the dotted spelling.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Nest an operation's address with a dot instead ('a.b'");

        ParticleMounter::segments('a/b');
    }

    public function test_a_one_word_name_mounts_exactly_where_it_did(): void
    {
        $this->assertSame('archive', ParticleMounter::segments('archive'));
        $this->assertSame('publish-version', ParticleMounter::segments('publish-version'));
        $this->assertSame('graph/neighbours', ParticleMounter::segments('graph.neighbours'));
    }

    // ── 2. A subject-free op is routed above the resource's `{id}` routes ─────────────────────────────

    public function test_get_uri_graph_reaches_a_subject_free_op_on_a_resource_that_also_mounts_show(): void
    {
        $this->register($this->op('yards', 'graph', subject: NoSubject::class));

        Particle::mount('yards')->ops(true)->register();

        $order = $this->order();
        $this->assertContains('yards.show', $order);
        $this->assertLessThan(
            array_search('yards.show', $order, true),
            array_search('yards.graph', $order, true),
            'the subject-free op must be registered above `show`: '.implode(', ', $order),
        );
        $this->assertSame('yards/graph', $this->uriOf('yards.graph'));

        $this->getJson('/yards/graph')->assertOk()->assertJson(['data' => ['op' => 'graph', 'subject' => null]]);

        // `show` still owns a real id: the resource's read gate answers it (closed in this harness, so
        // 403), not the op.
        $this->getJson('/yards/1')->assertForbidden();
    }

    /**
     * The re-take the ticket asks for (§3), and the proof that the order above is what does the work: the
     * same two routes in the OLD order (CRUD first, op second) send `GET /yards/graph` to `show` with
     * `id = "graph"`, and the op's handler never runs.
     */
    public function test_in_the_reversed_order_show_swallows_the_subject_free_op(): void
    {
        $ran = false;

        $this->register($this->op('yards', 'graph', subject: NoSubject::class, handle: function () use (&$ran) {
            $ran = true;

            return ['data' => ['op' => 'graph']];
        }));

        Particle::mount('yards')->register();
        Particle::ops('yards', 'yards', ['graph']);

        $this->assertLessThan(
            array_search('yards.graph', $this->order(), true),
            array_search('yards.show', $this->order(), true),
        );

        $response = $this->getJson('/yards/graph');

        $this->assertFalse($ran, 'the op ran, so `show` did not swallow it: the reversed-order hazard is not reproduced');
        $this->assertNotSame(['op' => 'graph'], $response->json('data'));
    }

    public function test_a_nested_subject_free_op_is_routed_above_a_record_op_of_the_same_tail(): void
    {
        $this->register($this->op('yards', 'cycles'));
        $this->register($this->op('yards', 'graph.cycles', subject: NoSubject::class));

        // The record op is listed FIRST; the mount still routes the subject-free one above it.
        Particle::mount('yards')->ops(['cycles', 'graph.cycles'])->register();

        $order = $this->order();
        $this->assertLessThan(array_search('yards.cycles', $order, true), array_search('yards.graph.cycles', $order, true));
        $this->assertSame('yards/graph/cycles', $this->uriOf('yards.graph.cycles'));
        $this->assertSame('yards/{id}/cycles', $this->uriOf('yards.cycles'));

        $this->getJson('/yards/graph/cycles')->assertOk()->assertJson(['data' => ['op' => 'graph.cycles']]);
        $this->getJson('/yards/1/cycles')->assertOk()->assertJson(['data' => ['op' => 'cycles', 'subject' => 1]]);
    }

    public function test_the_four_graph_addresses_of_d10_mount_together(): void
    {
        $this->register($this->op('yards', 'graph', subject: NoSubject::class));
        $this->register($this->op('yards', 'graph.cycles', subject: NoSubject::class));
        $this->register($this->op('yards', 'graph.neighbours'));
        $this->register($this->op('yards', 'graph.path'));

        Particle::mount('yards')->ops(true)->register();

        $this->assertSame('yards/graph', $this->uriOf('yards.graph'));
        $this->assertSame('yards/graph/cycles', $this->uriOf('yards.graph.cycles'));
        $this->assertSame('yards/{id}/graph/neighbours', $this->uriOf('yards.graph.neighbours'));
        $this->assertSame('yards/{id}/graph/path', $this->uriOf('yards.graph.path'));

        $this->getJson('/yards/graph')->assertOk()->assertJson(['data' => ['op' => 'graph']]);
        $this->getJson('/yards/graph/cycles')->assertOk()->assertJson(['data' => ['op' => 'graph.cycles']]);
        $this->getJson('/yards/1/graph/neighbours')->assertOk()->assertJson(['data' => ['op' => 'graph.neighbours', 'subject' => 1]]);
        $this->getJson('/yards/1/graph/path')->assertOk()->assertJson(['data' => ['op' => 'graph.path', 'subject' => 1]]);
    }

    // ── 3. The registry: one flat key, one split ─────────────────────────────────────────────────────

    public function test_two_registrations_sharing_a_flat_key_under_different_splits_are_refused_naming_both(): void
    {
        $registry = $this->app->make(ParticleOperationRegistry::class);

        $registry->register($this->op('a.b', 'c'));

        try {
            $registry->register($this->op('a', 'b.c'));
            $this->fail('the second registration of [a.b.c] was accepted');
        } catch (InvalidArgumentException $refused) {
            $this->assertStringContainsString('[b.c] on resource [a]', $refused->getMessage());
            $this->assertStringContainsString('[c] on resource [a.b]', $refused->getMessage());
            $this->assertStringContainsString('[a.b.c]', $refused->getMessage());
        }

        // The first registration still stands.
        $this->assertSame('a.b', $registry->find('a.b', 'c')?->resource);
    }

    public function test_the_same_split_registered_again_is_an_override_not_a_collision(): void
    {
        $registry = $this->app->make(ParticleOperationRegistry::class);

        $registry->register($first = $this->op('yards', 'graph.path'));
        $registry->register($second = $this->op('yards', 'graph.path'));

        $this->assertSame($second, $registry->find('yards', 'graph.path'));
        $this->assertNotSame($first, $registry->find('yards', 'graph.path'));
    }

    public function test_for_resource_returns_nested_names_but_not_a_dotted_resources_operations(): void
    {
        $registry = $this->app->make(ParticleOperationRegistry::class);

        $registry->register($this->op('yards', 'graph'));
        $registry->register($this->op('yards', 'graph.path'));
        $registry->register($this->op('yards.archive', 'restore'));

        $this->assertSame(
            ['graph', 'graph.path'],
            array_map(fn (ParticleOperation $op) => $op->name, $registry->forResource('yards')),
        );
    }

    public function test_a_branch_with_no_operation_of_its_own_is_absent_not_ambiguous(): void
    {
        $registry = $this->app->make(ParticleOperationRegistry::class);

        $registry->register($this->op('yards', 'graph.neighbours'));
        $registry->register($this->op('yards', 'graph.path'));

        $this->assertNull($registry->find('yards', 'graph'));
        $this->assertFalse($registry->has('yards', 'graph'));
    }
}

class NestedYard extends Model
{
    protected $table = 'yards';

    public $timestamps = false;

    protected $guarded = [];
}
