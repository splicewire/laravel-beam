<?php

namespace Splicewire\Beam\Tests\Agreements;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use ReflectionProperty;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Agreements\Data\OutstandingRequirementData;
use Splicewire\Beam\Agreements\Http\RequireAgreement;
use Splicewire\Beam\Agreements\Http\RouteRequirementGate;
use Splicewire\Beam\Agreements\RequirementDeclarations;
use Splicewire\Beam\Agreements\RequirementResolver;
use Splicewire\Beam\Agreements\Requires;
use Splicewire\Beam\Agreements\RouteRequirementIndex;
use Splicewire\Beam\Agreements\SlotRequirementDeclarations;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetData;
use Splicewire\Beam\Tests\TestCase;

/**
 * credential-scope 18: the route half of {@see SlotRequirementDeclarations} (agreements 07) is walked AT MOST ONCE per
 * request ({@see RouteRequirementIndex}), never kept across requests, and walked on every call outside one. Frame's
 * manifest asks `requirementsFor()` twice per resource projection; a walk per call made the flagship's Root manifest
 * about 4x slower. The map is unchanged, an edited or replaced route is enforced on the next request, and a route
 * changed mid-request after a read is refused loudly.
 *
 * The guards count real collection enumerations (`getRoutes()` and `count()`, and each route a cached collection
 * rebuilds), attributed by caller. Frame's actions index ({@see \Splicewire\Beam\Frame\ParticleResourceActions::route()})
 * makes its own enumeration per projection, unchanged by this ticket; the first guard pins that it is the only other
 * caller, so a new one fails here.
 */
class RouteDeclarationMemoTest extends TestCase
{
    private const RESOURCES = 6;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users.model', MemoReadActor::class);
        $app['config']->set('frame.middleware', ['web', 'auth']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('memo_read_actors', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('gadgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });

        for ($i = 1; $i <= self::RESOURCES; $i++) {
            app(ParticleResourceRegistry::class)->register(new ParticleResource(
                key: "memo-gadgets-{$i}",
                backing: Gadget::class,
                data: GadgetData::class,
                scope: fn ($query) => $query->whereNotNull('id'),
                project: fn (Gadget $gadget): GadgetData => new GadgetData((string) $gadget->getKey()),
                readOnly: true,
                label: "Memo gadgets {$i}",
                requires: $i === 1 ? [new Requires('terms', on: 'read')] : null,
            ));
        }

        Route::middleware('web')->group(function (): void {
            Route::get('/memo/report', fn () => ['ok' => true])->middleware('agreement:data-use')->name('memo.report');
            Route::get('/memo/plain', fn () => ['ok' => true])->name('memo.plain');
            Route::get('/login', fn () => 'login')->name('login');
        });
        app('router')->getRoutes()->refreshNameLookups();
        $this->actingAs(MemoReadActor::create(['name' => 'Ada']));
    }

    protected function tearDown(): void
    {
        RouteRequirementIndex::$verifiesInTests = true;

        parent::tearDown();
    }

    // ── the guard: one enumeration per request ──────────────────────────────────────────────────────────────────

    public function test_a_manifest_request_enumerates_the_routes_once_not_once_per_projection(): void
    {
        RouteRequirementIndex::$verifiesInTests = false;
        $counting = new CountingRouteCollection;
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $counting->add($route);
        }
        app('router')->setRoutes($counting);
        $counting->reset();

        $this->getJson('/frame/manifest')->assertOk();

        // Six projections ask twice each (read and write), and the route gate asks once: 13 reads, one enumeration.
        $this->assertSame(1, self::portEnumerations($counting), implode("\n", $counting->callers));
        $this->assertSame(['Splicewire\Beam\Frame\ParticleResourceActions::route'], array_values(array_unique(array_diff($counting->callers, [SlotRequirementDeclarations::class.'::routes']))));
    }

    public function test_with_cached_compiled_routes_a_manifest_request_enumerates_once_and_a_hit_rebuilds_no_route(): void
    {
        RouteRequirementIndex::$verifiesInTests = false;
        $routes = app('router')->getRoutes();
        foreach ($routes->getRoutes() as $route) {
            $route->prepareForSerialization();
        }
        $routes->refreshNameLookups();
        $compiled = $routes->compile();
        $collection = (new CountingCompiledRouteCollection($compiled['compiled'], $compiled['attributes']))
            ->setRouter(app('router'))
            ->setContainer($this->app);
        (new ReflectionProperty(Router::class, 'routes'))->setValue(app('router'), $collection);
        $this->app->instance('routes', $collection);
        $total = count($compiled['attributes']);
        $collection->reset();

        $this->getJson('/frame/manifest')->assertOk();
        $this->assertSame(1, self::portEnumerations($collection), implode("\n", $collection->callers));

        // Inside one scope, many reads: one enumeration, which rebuilds each cached route once; a hit rebuilds none.
        $collection->reset();
        RouteRequirementIndex::open();
        try {
            for ($i = 0; $i < 13; $i++) {
                app(RequirementDeclarations::class)->requirementsFor('route:memo.report');
            }
        } finally {
            RouteRequirementIndex::close();
        }
        $this->assertSame(1, $collection->enumerations);
        $this->assertSame($total, $collection->rebuilt);
    }

    public function test_outside_a_request_every_read_walks_and_a_late_route_is_seen(): void
    {
        $this->assertFalse(RouteRequirementIndex::isOpen());
        $this->assertArrayNotHasKey('route:memo.late', app(RequirementDeclarations::class)->all());

        Route::middleware('web')->get('/memo/late', fn () => 'late')->middleware('agreement:terms')->name('memo.late');
        app('router')->getRoutes()->refreshNameLookups();

        $this->assertEquals([new Requires('terms')], app(RequirementDeclarations::class)->requirementsFor('route:memo.late'));
    }

    // ── behaviour unchanged ─────────────────────────────────────────────────────────────────────────────────────

    public function test_the_memoised_map_equals_a_fresh_walk_for_every_behaviour(): void
    {
        $outside = app(RequirementDeclarations::class)->all();

        RouteRequirementIndex::open();
        try {
            $declarations = app(RequirementDeclarations::class);
            $first = $declarations->all();
            $second = app(RequirementDeclarations::class)->all();
        } finally {
            RouteRequirementIndex::close();
        }

        $this->assertEquals($outside, $first);
        $this->assertEquals($first, $second);
        $this->assertEquals($this->freshWalk(), $second);
        $this->assertEquals([new Requires('data-use')], $second['route:memo.report']);
        $this->assertArrayNotHasKey('route:memo.plain', $second);
        $this->assertEquals([new Requires('terms', on: 'read')], $second[app(ParticleResourceRegistry::class)->find('memo-gadgets-1')->readBehavior()]);
        foreach (array_keys($second) as $behavior) {
            $this->assertEquals($second[$behavior], $declarations->requirementsFor($behavior), $behavior);
        }
    }

    // ── the review's regressions: a warmed map never outlives its request ──────────────────────────────────────

    public function test_middleware_added_in_place_after_a_warm_request_is_enforced_on_the_next_request(): void
    {
        $seam = $this->bindSeam(['route:memo.plain']);

        $this->getJson('/memo/plain')->assertOk();
        $this->assertSame([], $seam->asked);

        $before = count(app('router')->getRoutes());
        app('router')->getRoutes()->getByName('memo.plain')->middleware('agreement:terms');
        $this->assertCount($before, app('router')->getRoutes());

        $this->getJson('/memo/plain')->assertStatus(428)->assertJsonPath('requirements.0.key', 'terms');
        $this->assertSame([['behavior' => 'route:memo.plain', 'subject' => null]], $seam->asked);
    }

    public function test_a_route_replaced_at_the_same_method_and_uri_after_a_warm_request_is_enforced_on_the_next_request(): void
    {
        $seam = $this->bindSeam(['route:memo.plain']);

        $this->getJson('/memo/plain')->assertOk();

        $before = count(app('router')->getRoutes());
        Route::middleware('web')->get('/memo/plain', fn () => ['ok' => 'replaced'])->middleware('agreement:terms')->name('memo.plain');
        app('router')->getRoutes()->refreshNameLookups();
        $this->assertCount($before, app('router')->getRoutes());

        $this->getJson('/memo/plain')->assertStatus(428);
        $this->assertSame([['behavior' => 'route:memo.plain', 'subject' => null]], $seam->asked);
    }

    public function test_successive_requests_on_one_collection_each_walk_once(): void
    {
        RouteRequirementIndex::$verifiesInTests = false;
        $counting = new CountingRouteCollection;
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $counting->add($route);
        }
        app('router')->setRoutes($counting);
        $counting->reset();

        $this->getJson('/frame/manifest')->assertOk();
        $this->getJson('/frame/manifest')->assertOk();

        $this->assertSame(2, self::portEnumerations($counting));
        $this->assertFalse(RouteRequirementIndex::isOpen());
    }

    public function test_a_route_changed_mid_request_after_a_read_is_refused_loudly(): void
    {
        Route::middleware('web')->get('/memo/mutate', function () {
            app(RequirementDeclarations::class)->all();
            app('router')->getRoutes()->getByName('memo.plain')->middleware('agreement:terms');

            return ['ok' => true];
        })->name('memo.mutate');
        app('router')->getRoutes()->refreshNameLookups();
        $this->withoutExceptionHandling();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('changed after they were read');

        $this->getJson('/memo/mutate');
    }

    /** The enumerations the requirements port made (the frame actions index makes its own; see the test docblock). */
    private static function portEnumerations(object $collection): int
    {
        return count(array_filter($collection->callers, fn (string $caller): bool => $caller === SlotRequirementDeclarations::class.'::routes'));
    }

    // ── fixtures ────────────────────────────────────────────────────────────────────────────────────────────────

    /** A fake agreements seam: `terms` outstanding for each gated behaviour (the RouteRequirementTest shape). */
    private function bindSeam(array $gated): MemoSeamProbe
    {
        $probe = new MemoSeamProbe;
        $probe->gated = $gated;
        $this->app->instance(RequirementResolver::class, $probe);

        return $probe;
    }

    /** The map as agreements 07 computed it: every route walked now, no index. */
    private function freshWalk(): array
    {
        $declared = (new SlotRequirementDeclarationsWithoutRoutes(app(ParticleOperationRegistry::class), app(ParticleResourceRegistry::class)))->all();

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || $name === '') {
                continue;
            }
            foreach (RequireAgreement::declaredOn($route) as $declaration) {
                $declared[RouteRequirementGate::BEHAVIOR_PREFIX.$name][] = $declaration['requires'];
            }
        }

        return $declared;
    }
}

/** The registries half only, for the reference walk. */
class SlotRequirementDeclarationsWithoutRoutes extends SlotRequirementDeclarations
{
    protected function routeDeclarations(): array
    {
        return [];
    }
}

/** Counts every enumeration of the collection: `getRoutes()` and `count()` (which enumerates). */
class CountingRouteCollection extends RouteCollection
{
    public int $enumerations = 0;

    /** @var list<string> who enumerated, for a failing assertion's message */
    public array $callers = [];

    public function getRoutes()
    {
        $this->enumerations++;
        $this->callers[] = self::caller();

        return parent::getRoutes();
    }

    public function count(): int
    {
        $this->enumerations++;
        $this->callers[] = self::caller();

        return count(parent::getRoutes());
    }

    public function reset(): void
    {
        $this->enumerations = 0;
        $this->callers = [];
    }

    public static function caller(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $frame) {
            $class = $frame['class'] ?? '';
            if ($class !== '' && ! str_contains($class, 'RouteCollection') && ! str_starts_with($class, 'Illuminate\\Support')) {
                return $class.'::'.($frame['function'] ?? '?');
            }
        }

        return '?';
    }
}

/** A cached (compiled) collection that counts enumerations and each route it rebuilds from the cache. */
class CountingCompiledRouteCollection extends CompiledRouteCollection
{
    public int $enumerations = 0;

    public int $rebuilt = 0;

    /** @var list<string> */
    public array $callers = [];

    public function reset(): void
    {
        $this->enumerations = 0;
        $this->rebuilt = 0;
        $this->callers = [];
    }

    public function getRoutes()
    {
        $this->enumerations++;
        $this->callers[] = CountingRouteCollection::caller();

        return parent::getRoutes();
    }

    public function count(): int
    {
        $this->enumerations++;
        $this->callers[] = CountingRouteCollection::caller();

        return count(parent::getRoutes());
    }

    protected function newRoute(array $attributes)
    {
        $this->rebuilt++;

        return parent::newRoute($attributes);
    }
}

class MemoSeamProbe implements RequirementResolver
{
    /** @var list<string> */
    public array $gated = [];

    /** @var list<array{behavior: string, subject: mixed}> */
    public array $asked = [];

    public function outstanding(mixed $party, string $behavior, mixed $subject, array $submittedMembers): array
    {
        $this->asked[] = ['behavior' => $behavior, 'subject' => $subject];

        if (! in_array($behavior, $this->gated, true)) {
            return [];
        }

        return [new OutstandingRequirementData(
            key: 'terms', version: 1, digest: str_repeat('d', 64), kind: 'contract', title: 'Terms', scope: 'party',
            subject: null, readUrl: '/beam/agreements/agreements/terms', acceptOperation: 'agreements.accept', member: null,
        )];
    }
}

class MemoReadActor extends User
{
    protected $table = 'memo_read_actors';

    protected $guarded = [];

    public $timestamps = false;
}
