<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rushing\DataFilters\Attributes\Sortable;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleListQuery;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * ONE declaration, TWO transports, ONE base query (particle-contribution-seam ticket 05).
 *
 * Both transports serve a non-filterable list off the same `ParticleResource`, and each used to
 * implement exactly the half the other was missing:
 *
 *   - REST (`ParticleController::defaultSortedQuery`) honoured the declared `#[Sortable(default: true)]`
 *     order and NEVER eager-loaded `includes` — the include list was dropped on every list read.
 *   - Frame (`ParticleFrameResourceHandler::indexQuery`) eager-loaded the includes and then hardcoded
 *     `orderByDesc('created_at')`, ignoring the sortable attribute entirely.
 *
 * One test per cell of that table, plus the unit assertions on the shared builder itself.
 */
class ParticleListQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('list_crates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('weight');
            $table->timestamps();
        });

        Schema::create('list_crate_labels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('list_crate_id');
            $table->string('text');
        });

        // 'heavy' is created OLDEST, so a `created_at desc` order surfaces 'light' — the declared
        // `weight desc` order must surface 'heavy'. The two orders disagree by construction, and the
        // timestamps are distinct so neither order can pass by insertion luck.
        foreach ([['heavy', 9, '2020-01-01'], ['middling', 5, '2020-02-01'], ['light', 1, '2020-03-01']] as [$name, $weight, $at]) {
            $crate = ListCrate::create(['name' => $name, 'weight' => $weight, 'created_at' => $at, 'updated_at' => $at]);
            ListCrateLabel::create(['list_crate_id' => $crate->id, 'text' => $name.'-label']);
        }

        // `label:` makes it framed, so BOTH transports serve this one declaration — which is the
        // premise the whole test exists to pin.
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'list-crate',
            backing: ListCrate::class,
            data: ListCrateData::class,
            includes: ['labels'],
            filterable: false,
            label: 'List Crates',
        ));
    }

    private function resource(): ParticleResource
    {
        return $this->app->make(ParticleResourceRegistry::class)->get('list-crate');
    }

    // ── The shared builder ──────────────────────────────────────────────────────────────────────────

    public function test_the_list_base_eager_loads_declared_includes(): void
    {
        $query = (new ParticleListQuery)->forList($this->resource());

        $this->assertArrayHasKey('labels', $query->getEagerLoads());
    }

    public function test_the_list_base_orders_by_the_declared_default_sort(): void
    {
        $names = (new ParticleListQuery)->forList($this->resource())->pluck('name')->all();

        $this->assertSame(['heavy', 'middling', 'light'], $names);
    }

    public function test_the_list_base_falls_back_to_the_framework_default_without_a_data_class(): void
    {
        $resource = new ParticleResource(key: 'bare-crate', backing: ListCrate::class, filterable: false);

        // No `data:` ⇒ nothing to reflect a `#[Sortable]` off, so newest-`created_at`-first. 'light' is
        // the newest, so it leads — the exact inverse of the declared weight order above.
        $this->assertSame('light', (new ParticleListQuery)->forList($resource)->first()->name);
    }

    // ── The REST cell: includes were never applied ──────────────────────────────────────────────────

    public function test_the_rest_index_eager_loads_declared_includes(): void
    {
        $request = Request::create('/list-crates');
        $route = new Route('GET', '/list-crates', []);
        $route->defaults(ParticleController::RESOURCE, 'list-crate');
        $request->setRouteResolver(fn () => $route);

        DB::enableQueryLog();
        $this->app->make(ParticleController::class)->index($request)->toResponse($request);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Eager-loaded ⇒ exactly ONE query against the labels table for the whole page. Before ticket 05
        // this was ZERO: the declared include never reached the REST index query at all. (It reads as an
        // N+1 only for a Data class that projects the relation; here it was inert. Same root cause, and
        // the contract under test is the same either way — a declared include reaches the query.)
        $labelQueries = array_filter($queries, fn (array $q) => str_contains($q['query'], 'list_crate_labels'));

        $this->assertCount(1, $labelQueries);
    }

    // ── The Frame cell: the declared sort was hardcoded away ────────────────────────────────────────

    public function test_the_frame_index_honours_the_declared_default_sort(): void
    {
        $rows = $this->app->make(ParticleFrameResourceHandler::class)->index(
            $this->app->make(ParticleResourceRegistry::class)->definition('list-crate'),
            [],
        );

        // Previously `orderByDesc('created_at')` — which would have returned light, middling, heavy.
        $this->assertSame(['heavy', 'middling', 'light'], array_column($rows, 'name'));
    }

    public function test_the_frame_index_eager_loads_declared_includes(): void
    {
        DB::enableQueryLog();
        $this->app->make(ParticleFrameResourceHandler::class)->index(
            $this->app->make(ParticleResourceRegistry::class)->definition('list-crate'),
            [],
        );
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $labelQueries = array_filter($queries, fn (array $q) => str_contains($q['query'], 'list_crate_labels'));

        $this->assertCount(1, $labelQueries);
    }
}

class ListCrate extends Model
{
    protected $table = 'list_crates';

    protected $guarded = [];

    public function labels(): HasMany
    {
        return $this->hasMany(ListCrateLabel::class, 'list_crate_id');
    }
}

class ListCrateLabel extends Model
{
    protected $table = 'list_crate_labels';

    protected $guarded = [];

    public $timestamps = false;
}

class ListCrateData extends Data
{
    // The declared default order — `weight desc` — deliberately disagrees with `created_at desc`, so a
    // transport that hardcodes the framework default fails these assertions rather than passing by luck.
    public function __construct(
        public int $id,
        public string $name,
        #[Sortable(default: true, direction: 'desc')]
        public ?int $weight = null,
    ) {}
}
