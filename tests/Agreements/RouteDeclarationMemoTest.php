<?php

namespace Splicewire\Beam\Tests\Agreements;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Agreements\RequirementDeclarations;
use Splicewire\Beam\Agreements\Requires;
use Splicewire\Beam\Agreements\SlotRequirementDeclarations;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\Gadget;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\GadgetData;
use Splicewire\Beam\Tests\TestCase;

/**
 * credential-scope 18: the route half of {@see SlotRequirementDeclarations} (agreements 07) is walked once per route
 * collection and count, not once per call. Frame's manifest asks `requirementsFor()` twice per resource projection; a
 * walk per call made the flagship's Root manifest about 4x slower. The map it answers is unchanged, and a route
 * registered after a read is still seen.
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
        $app['config']->set('app.key', str_repeat('m', 32));
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

        CountingSlotRequirementDeclarations::$walks = 0;
        $this->app->bind(RequirementDeclarations::class, CountingSlotRequirementDeclarations::class);

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

        Route::get('/memo/report', fn () => 'report')->middleware('agreement:data-use')->name('memo.report');
        Route::get('/memo/plain', fn () => 'plain')->name('memo.plain');
        app('router')->getRoutes()->refreshNameLookups();
        $this->actingAs(MemoReadActor::create(['name' => 'Ada']));
    }

    public function test_a_manifest_request_walks_the_routes_once_not_once_per_projection(): void
    {
        $this->getJson('/frame/manifest')->assertOk();

        // Each of the six projections asks twice (read and write behaviour), so a walk per call would be 12 or more.
        $this->assertSame(1, CountingSlotRequirementDeclarations::$walks);
    }

    public function test_repeated_projections_in_one_request_share_one_walk(): void
    {
        $registry = app(ParticleResourceRegistry::class);

        for ($i = 1; $i <= self::RESOURCES; $i++) {
            $registry->find("memo-gadgets-{$i}")->toResourceDefinition();
        }

        $this->assertSame(1, CountingSlotRequirementDeclarations::$walks);
    }

    public function test_the_memoised_map_equals_a_fresh_walk_for_every_behaviour(): void
    {
        $declarations = app(RequirementDeclarations::class);
        $first = $declarations->all();
        $second = app(RequirementDeclarations::class)->all();

        $this->assertEquals($first, $second);
        $this->assertEquals($this->freshWalk(), $second);
        $this->assertEquals([new Requires('data-use')], $second['route:memo.report']);
        $this->assertArrayNotHasKey('route:memo.plain', $second);
        $this->assertEquals([new Requires('terms', on: 'read')], $second[app(ParticleResourceRegistry::class)->find('memo-gadgets-1')->readBehavior()]);

        foreach (array_keys($second) as $behavior) {
            $this->assertEquals($second[$behavior], $declarations->requirementsFor($behavior), $behavior);
        }
        $this->assertSame([], $declarations->requirementsFor('route:memo.plain'));
    }

    public function test_a_route_registered_after_a_read_is_still_seen(): void
    {
        $this->assertArrayNotHasKey('route:memo.late', app(RequirementDeclarations::class)->all());

        Route::get('/memo/late', fn () => 'late')->middleware('agreement:terms')->name('memo.late');
        app('router')->getRoutes()->refreshNameLookups();

        $this->assertEquals([new Requires('terms')], app(RequirementDeclarations::class)->requirementsFor('route:memo.late'));
        $this->assertEquals($this->freshWalk(), app(RequirementDeclarations::class)->all());
        $this->assertSame(2, CountingSlotRequirementDeclarations::$walks);
    }

    public function test_a_new_route_collection_is_never_answered_from_another_s_entry(): void
    {
        app(RequirementDeclarations::class)->all();

        $replacement = new \Illuminate\Routing\RouteCollection;
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if ($route->getName() !== 'memo.report') {
                $replacement->add($route);
            }
        }
        // The same count, so only the collection's identity tells the two apart.
        $replacement->add((new \Illuminate\Routing\Route(['GET'], 'memo/other', fn () => 'other'))->name('memo.other'));
        $this->assertCount(count(app('router')->getRoutes()), $replacement);
        app('router')->setRoutes($replacement);

        $this->assertArrayNotHasKey('route:memo.report', app(RequirementDeclarations::class)->all());
    }

    /** The map as the un-memoised implementation computed it: a bare declarations object walking every route now. */
    private function freshWalk(): array
    {
        return (new UnmemoisedSlotRequirementDeclarations(
            app(\Splicewire\Beam\Particle\ParticleOperationRegistry::class),
            app(ParticleResourceRegistry::class),
        ))->all();
    }
}

/** Counts the route walks {@see SlotRequirementDeclarations::routeDeclarations()} makes. */
class CountingSlotRequirementDeclarations extends SlotRequirementDeclarations
{
    public static int $walks = 0;

    protected function routes(RouteCollectionInterface $collection): iterable
    {
        self::$walks++;

        return parent::routes($collection);
    }
}

/** The reference: the agreements 07 walk on every call, with no memo. */
class UnmemoisedSlotRequirementDeclarations extends SlotRequirementDeclarations
{
    protected function routeDeclarations(): array
    {
        $declared = [];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || $name === '') {
                continue;
            }
            foreach (\Splicewire\Beam\Agreements\Http\RequireAgreement::declaredOn($route) as $declaration) {
                $declared[\Splicewire\Beam\Agreements\Http\RouteRequirementGate::BEHAVIOR_PREFIX.$name][] = $declaration['requires'];
            }
        }

        return $declared;
    }
}

class MemoReadActor extends User
{
    protected $table = 'memo_read_actors';

    protected $guarded = [];

    public $timestamps = false;
}
