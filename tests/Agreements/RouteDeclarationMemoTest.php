<?php

namespace Splicewire\Beam\Tests\Agreements;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use ReflectionProperty;
use RuntimeException;
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
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * credential-scope 18: the route half of {@see SlotRequirementDeclarations} (agreements 07) is walked AT MOST ONCE per
 * request ({@see RouteRequirementIndex}), never kept across requests, and walked on every call outside one. Frame's
 * manifest asks `requirementsFor()` twice per resource projection; a walk per call made the flagship's Root manifest
 * about 4x slower. The map is unchanged, an edited or replaced route is enforced on the next request, and a route
 * changed mid-request after a read is refused loudly.
 *
 * The memo is a stack of frames: a sync job or an `Artisan::call` inside a request walks once for itself and leaves the
 * request's memo intact; Octane's RequestReceived + Routing pair is one frame; a top-level open starts alone and empty
 * after a unit whose close never fired; a nested frame left open is popped (and verified) with its parent.
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
        MemoUnitLog::reset();

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
        MemoUnitLog::reset();

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

    // ── nesting (review cs18 completion): a nested unit keeps its parent's memo; sequential units start empty ────

    public function test_a_sync_job_and_an_artisan_call_inside_a_request_keep_the_parent_requests_memo(): void
    {
        RouteRequirementIndex::$verifiesInTests = false;
        $this->app['config']->set('queue.default', 'sync');
        Artisan::command('memo:read', function (): void {
            MemoUnitLog::measure('command', fn () => [app(RequirementDeclarations::class)->all(), app(RequirementDeclarations::class)->requirementsFor('route:memo.report')]);
        });
        $this->routeCommandEvents();
        Route::middleware('web')->get('/memo/nested', function () {
            MemoUnitLog::measure('parent', fn () => app(RequirementDeclarations::class)->all());
            dispatch(new MemoReadingJob);
            Artisan::call('memo:read');
            MemoUnitLog::measure('parent', fn () => [app(RequirementDeclarations::class)->all(), app(RequirementDeclarations::class)->requirementsFor('route:memo.report')]);

            return ['ok' => true];
        })->name('memo.nested');
        $counting = $this->countingRoutes();

        $this->getJson('/memo/nested')->assertOk();

        // The parent walks once (its route gate and its first read share it), the job and the command once each.
        $this->assertSame(1, MemoUnitLog::walks('parent'), implode("\n", $counting->callers));
        $this->assertSame(1, MemoUnitLog::walks('job'));
        $this->assertSame(1, MemoUnitLog::walks('command'));
        $this->assertSame(3, self::portEnumerations($counting));
        $this->assertSame([1, 1], MemoUnitLog::$depths['parent']);
        $this->assertSame([2], MemoUnitLog::$depths['job'], 'the job nests on the request frame');
        $this->assertSame([2], MemoUnitLog::$depths['command'], 'the command nests on the request frame');
        $this->assertFalse(RouteRequirementIndex::isOpen());
    }

    public function test_octanes_request_received_and_routing_for_one_request_give_one_frame(): void
    {
        RouteRequirementIndex::$verifiesInTests = false;
        Route::get('/memo/octane', function () {
            MemoUnitLog::measure('request', fn () => app(RequirementDeclarations::class)->all());

            return ['ok' => true];
        })->name('memo.octane');
        $counting = $this->countingRoutes();
        $request = Request::create('/memo/octane');

        event('Laravel\\Octane\\Events\\RequestReceived', [new OctaneRequestEventStub($this->app, $request)]);
        MemoUnitLog::measure('received', fn () => app(RequirementDeclarations::class)->all());
        $response = $this->app->make(HttpKernel::class)->handle($request);
        event('Laravel\\Octane\\Events\\RequestTerminated', [new OctaneRequestEventStub($this->app, $request)]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, MemoUnitLog::walks('received'));
        $this->assertSame(0, MemoUnitLog::walks('request'), 'Routing for the same request keeps the RequestReceived frame and its map');
        $this->assertSame([1], MemoUnitLog::$depths['request']);
        $this->assertSame(1, self::portEnumerations($counting));
        $this->assertFalse(RouteRequirementIndex::isOpen());
    }

    public function test_a_top_level_open_starts_alone_and_empty_after_a_unit_whose_close_never_fired(): void
    {
        // A request whose close never fired (its exception escaped the kernel), then the next request.
        RouteRequirementIndex::openRequest(Request::create('/memo/crashed'));
        $stale = app(RequirementDeclarations::class)->all();
        Route::get('/memo/next', function () {
            MemoUnitLog::measure('next', fn () => MemoUnitLog::$seen['next'] = array_key_exists('route:memo.next', app(RequirementDeclarations::class)->all()));

            return ['ok' => true];
        })->middleware('agreement:terms')->name('memo.next');
        app('router')->getRoutes()->refreshNameLookups();
        $this->assertArrayNotHasKey('route:memo.next', $stale);

        $this->getJson('/memo/next')->assertOk();

        $this->assertSame([1], MemoUnitLog::$depths['next'], 'the request frame is the only one');
        $this->assertTrue(MemoUnitLog::$seen['next'], 'the new request reads its own walk, not the stale frame\'s map');
        $this->assertFalse(RouteRequirementIndex::isOpen());

        // A worker's job whose close never fired, then the next job: it starts alone and empty, and sees a new route.
        event(new JobProcessing('redis', $crashed = $this->queueJob()));
        app(RequirementDeclarations::class)->all();
        Route::get('/memo/later', fn () => 'later')->middleware('agreement:terms')->name('memo.later');
        app('router')->getRoutes()->refreshNameLookups();
        event(new JobProcessing('redis', $next = $this->queueJob()));
        $this->assertSame(1, RouteRequirementIndex::depth());
        $this->assertArrayHasKey('route:memo.later', app(RequirementDeclarations::class)->all());
        event(new JobProcessed('redis', $next));
        event(new JobProcessed('redis', $crashed));
        $this->assertFalse(RouteRequirementIndex::isOpen());
    }

    public function test_a_nested_frame_whose_close_never_fired_is_popped_with_its_parent(): void
    {
        Route::get('/memo/orphan', function () {
            RouteRequirementIndex::open(new MemoUnitLog); // a nested unit whose close never fires
            app(RequirementDeclarations::class)->all();
            MemoUnitLog::$depths['orphan'][] = RouteRequirementIndex::depth();

            return ['ok' => true];
        })->name('memo.orphan');
        app('router')->getRoutes()->refreshNameLookups();

        $this->getJson('/memo/orphan')->assertOk();

        $this->assertSame([2], MemoUnitLog::$depths['orphan']);
        $this->assertFalse(RouteRequirementIndex::isOpen(), 'the parent close pops the orphan too');
    }

    public function test_a_route_changed_after_a_read_in_a_nested_frame_left_open_is_refused_at_the_parents_close(): void
    {
        // Outside the web group, so the route gate never reads: only the nested frame holds a map.
        Route::get('/memo/orphan-mutate', function () {
            RouteRequirementIndex::open(new MemoUnitLog);
            app(RequirementDeclarations::class)->all();
            app('router')->getRoutes()->getByName('memo.plain')->middleware('agreement:terms');

            return ['ok' => true];
        })->name('memo.orphan-mutate');
        app('router')->getRoutes()->refreshNameLookups();
        $this->withoutExceptionHandling();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('changed after they were read');

        $this->getJson('/memo/orphan-mutate');
    }

    public function test_a_close_whose_unit_has_no_frame_closes_nothing(): void
    {
        $request = Request::create('/memo/open');
        RouteRequirementIndex::openRequest($request);

        try {
            // queue:work's own frame was cleared by its first job; a job's JobProcessed after its JobFailed.
            event(new CommandFinished('queue:work', new ArrayInput([]), new NullOutput, 0));
            event(new JobProcessed('sync', $this->queueJob()));
            $this->assertSame(1, RouteRequirementIndex::depth());
        } finally {
            RouteRequirementIndex::close($request);
        }

        $this->assertFalse(RouteRequirementIndex::isOpen());
    }

    public function test_a_workers_job_that_throws_closes_its_frame_at_job_exception_occurred(): void
    {
        $job = $this->queueJob();
        event(new JobProcessing('redis', $job));
        $this->assertSame(1, RouteRequirementIndex::depth());

        // A retried job fires neither JobProcessed nor JobFailed.
        event(new JobExceptionOccurred('redis', $job, new RuntimeException('retry me')));

        $this->assertFalse(RouteRequirementIndex::isOpen());
    }

    public function test_a_sync_job_inside_a_plain_console_command_keeps_the_commands_memo(): void
    {
        // `php artisan <cmd>` with no request: the job runs on the sync connection, inside the command, so it nests.
        RouteRequirementIndex::$verifiesInTests = false;
        $this->app['config']->set('queue.default', 'sync');
        Artisan::command('memo:dispatch', function (): void {
            MemoUnitLog::measure('command', fn () => app(RequirementDeclarations::class)->all());
            MemoReadingJob::dispatchSync();
            MemoUnitLog::measure('command', fn () => [app(RequirementDeclarations::class)->all(), app(RequirementDeclarations::class)->requirementsFor('route:memo.report')]);
        });
        $this->routeCommandEvents();
        $counting = $this->countingRoutes();

        Artisan::call('memo:dispatch');

        $this->assertSame(1, MemoUnitLog::walks('command'), 'the command walks once for itself; its sync job never clears its frame');
        $this->assertSame(1, MemoUnitLog::walks('job'));
        $this->assertSame(2, self::portEnumerations($counting), implode("\n", $counting->callers));
        $this->assertSame([1, 1], MemoUnitLog::$depths['command']);
        $this->assertSame([2], MemoUnitLog::$depths['job'], 'the sync job nests on the command frame');
        $this->assertFalse(RouteRequirementIndex::isOpen());
    }

    public function test_a_job_closed_by_job_exception_occurred_then_job_failed_closes_once_and_keeps_its_parent(): void
    {
        $request = Request::create('/memo/double-close');
        RouteRequirementIndex::openRequest($request);

        try {
            $job = $this->queueJob();
            event(new JobProcessing('sync', $job));
            $this->assertSame(2, RouteRequirementIndex::depth());

            event(new JobExceptionOccurred('sync', $job, new RuntimeException('fails')));
            $this->assertSame(1, RouteRequirementIndex::depth());

            // the second close for the same job finds no frame of its own: it must not pop the request frame
            event(new JobFailed('sync', $job, new RuntimeException('fails')));
            $this->assertSame(1, RouteRequirementIndex::depth());
        } finally {
            RouteRequirementIndex::close($request);
        }

        $this->assertFalse(RouteRequirementIndex::isOpen());
    }

    public function test_a_workers_job_on_a_queue_connection_stays_top_level(): void
    {
        // queue:work's own command frame, then a worker job: top-level, so it clears the stack and starts alone.
        RouteRequirementIndex::open($input = new MemoUnitLog);
        event(new JobProcessing('redis', $job = $this->queueJob()));
        $this->assertSame(1, RouteRequirementIndex::depth());
        event(new JobProcessed('redis', $job));
        RouteRequirementIndex::close($input);
        $this->assertFalse(RouteRequirementIndex::isOpen());
    }

    /** The enumerations the requirements port made (the frame actions index makes its own; see the test docblock). */
    private static function portEnumerations(object $collection): int
    {
        return count(array_filter($collection->callers, fn (string $caller): bool => $caller === SlotRequirementDeclarations::class.'::routes'));
    }

    /** Swap the router's collection for one that counts enumerations (after the test's routes are registered). */
    private function countingRoutes(): CountingRouteCollection
    {
        $counting = new CountingRouteCollection;
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $counting->add($route);
        }
        app('router')->setRoutes($counting);
        $counting->reset();

        return $counting;
    }

    /** Laravel fires CommandStarting/CommandFinished only outside unit tests; route them here, as a console run does. */
    private function routeCommandEvents(): void
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->setArtisan(null);
    }

    private function queueJob(): SyncJob
    {
        return new SyncJob($this->app, '{"uuid":"memo","job":"memo","data":[]}', 'sync', 'default');
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

/** What each unit (parent request, job, command) saw: its own port walks and the frame depth at each measure. */
class MemoUnitLog
{
    /** @var array<string, int> */
    public static array $walks = [];

    /** @var array<string, list<int>> */
    public static array $depths = [];

    /** @var array<string, bool> */
    public static array $seen = [];

    public static function measure(string $unit, Closure $reads): void
    {
        $routes = app('router')->getRoutes();
        $before = self::portWalks($routes);
        self::$depths[$unit][] = RouteRequirementIndex::depth();
        $reads();
        self::$walks[$unit] = (self::$walks[$unit] ?? 0) + self::portWalks($routes) - $before;
    }

    public static function walks(string $unit): int
    {
        return self::$walks[$unit] ?? 0;
    }

    public static function reset(): void
    {
        self::$walks = [];
        self::$depths = [];
        self::$seen = [];
    }

    private static function portWalks(object $routes): int
    {
        return count(array_filter($routes->callers ?? [], fn (string $caller): bool => $caller === SlotRequirementDeclarations::class.'::routes'));
    }
}

/** A sync job inside a request that reads the requirements twice. */
class MemoReadingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        MemoUnitLog::measure('job', fn () => [app(RequirementDeclarations::class)->all(), app(RequirementDeclarations::class)->requirementsFor('route:memo.report')]);
    }
}

/** The fields beam's listeners read from Octane's RequestReceived / RequestTerminated (beam does not depend on Octane). */
class OctaneRequestEventStub
{
    public function __construct(public object $sandbox, public object $request) {}
}
