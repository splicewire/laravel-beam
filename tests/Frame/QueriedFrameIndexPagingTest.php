<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rushing\DataFilters\Attributes\Sortable;
use Schemastud\Frame\Data\ResourcePageData;
use Schemastud\Frame\FrameServiceProvider;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\ReadGuard\OpenReadPolicy;
use Splicewire\Beam\Tests\TestCase;

/**
 * attention-surfaces 29, finding 1: a queryable resource's Frame list is paged IN the query. Before, the handler
 * loaded, projected and enriched every row and Frame sliced the page out in memory; the wire page must not change.
 *
 * "Before" is reconstructed exactly as Frame built it: `ResourcePageData::offset(array_slice(<every row>, …))`, with
 * every row read through {@see ParticleFrameResourceHandler::rows()}, the whole-list read the old index() was.
 */
class QueriedFrameIndexPagingTest extends TestCase
{
    private const ROWS = 27;

    protected function getPackageProviders($app): array
    {
        return [FrameServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('frame.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(new GenericUser(['id' => 1, 'name' => 'Paged Reader']));
        $this->app['request']->setUserResolver(fn () => auth()->user());
        Gate::policy(PagedCrate::class, OpenReadPolicy::class);

        Schema::create('paged_crates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('weight');
            $table->timestamps();
        });

        // The declared order (weight desc) disagrees with insertion and with `latest()`, so a page that lost
        // the declared sort cannot pass by luck.
        foreach (range(1, self::ROWS) as $i) {
            $at = sprintf('2020-01-%02d 00:00:00', $i);
            PagedCrate::create(['name' => "crate-{$i}", 'weight' => ($i * 7) % 29, 'created_at' => $at, 'updated_at' => $at]);
        }

        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'paged-crates',
            backing: PagedCrate::class,
            data: PagedCrateData::class,
            // A declared row scope, so beam-docs-satellite 65's read guard admits the central list.
            scope: fn (Builder $query): Builder => $query->whereNotNull($query->getModel()->getQualifiedKeyName()),
            frame: true,
            readOnly: true,
        ));
    }

    public function test_the_list_query_is_bounded_to_the_page(): void
    {
        $hydrated = 0;
        Event::listen('eloquent.retrieved: '.PagedCrate::class, function () use (&$hydrated): void {
            $hydrated++;
        });
        DB::enableQueryLog();

        $page = $this->getJson('/frame/resources/paged-crates?page=2&per_page=4')->assertOk()->json();

        $selects = array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $sql): bool => str_contains($sql, 'from "paged_crates"'),
        ));
        DB::disableQueryLog();

        $this->assertSame(4, $hydrated, 'only the requested page is hydrated');
        $this->assertCount(4, $page['data']);
        $this->assertSame(self::ROWS, $page['total']);
        $this->assertCount(2, $selects, 'one count and one page read');
        $this->assertStringContainsString('count(*)', $selects[0]);
        $this->assertStringContainsString('limit 4 offset 4', $selects[1]);
    }

    public function test_every_page_equals_the_slice_frame_used_to_cut_from_the_whole_list(): void
    {
        $all = $this->allRows();
        $this->assertSame(
            collect(range(1, self::ROWS))->sortByDesc(fn (int $i): int => ($i * 7) % 29)->map(fn (int $i): string => "crate-{$i}")->values()->all(),
            array_column($all, 'name'),
            'the whole list keeps the declared order',
        );

        foreach ([[[], 1, 25], [['page' => 2], 2, 25], [['page' => 1, 'per_page' => 10], 1, 10], [['page' => 3, 'per_page' => 10], 3, 10]] as [$query, $page, $perPage]) {
            $this->assertSame(
                ResourcePageData::offset(array_slice($all, ($page - 1) * $perPage, $perPage), self::ROWS, $page, $perPage)->toArray(),
                $this->getJson('/frame/resources/paged-crates?'.http_build_query($query))->assertOk()->json(),
                'page '.$page.' of '.$perPage,
            );
        }
    }

    public function test_the_pages_concatenate_to_the_whole_list_in_order(): void
    {
        $names = [];
        foreach ([1, 2, 3] as $page) {
            $names = [...$names, ...array_column($this->getJson("/frame/resources/paged-crates?page={$page}&per_page=10")->json('data'), 'name')];
        }

        $this->assertSame(array_column($this->allRows(), 'name'), $names);
    }

    public function test_a_page_past_the_end_answers_no_rows_and_the_true_total(): void
    {
        $this->assertSame(
            ['data' => [], 'perPage' => 10, 'total' => self::ROWS, 'page' => 9],
            array_intersect_key(
                $this->getJson('/frame/resources/paged-crates?page=9&per_page=10')->assertOk()->json(),
                array_flip(['data', 'perPage', 'total', 'page']),
            ),
        );
    }

    public function test_the_handler_pages_a_direct_call_with_frames_own_floor_default_and_cap(): void
    {
        $handler = $this->app->make(ParticleFrameResourceHandler::class);
        $definition = $this->app->make(ParticleResourceRegistry::class)->definition('paged-crates');

        $default = $handler->index($definition, []);
        $this->assertSame([25, 1, self::ROWS, 25], [$default['perPage'], $default['page'], $default['total'], count($default['data'])]);
        $this->assertSame(
            ResourcePageData::offset(array_slice($this->allRows(), 0, 25), self::ROWS, 1, 25)->toArray(),
            $default,
            'a direct call answers Frame\'s own offset envelope, key for key',
        );

        $capped = $handler->index($definition, ['per_page' => 500, 'page' => 0]);
        $this->assertSame([100, 1, self::ROWS], [$capped['perPage'], $capped['page'], count($capped['data'])]);

        $floored = $handler->index($definition, ['per_page' => 0]);
        $this->assertSame([1, 1], [$floored['perPage'], count($floored['data'])]);

        $this->assertCount(self::ROWS, $handler->rows($definition));
    }

    /** @return list<array<string, mixed>> */
    private function allRows(): array
    {
        return $this->app->make(ParticleFrameResourceHandler::class)
            ->rows($this->app->make(ParticleResourceRegistry::class)->definition('paged-crates'));
    }
}

class PagedCrate extends Model
{
    protected $table = 'paged_crates';

    protected $guarded = [];
}

class PagedCrateData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        #[Sortable(default: true, direction: 'desc')]
        public ?int $weight = null,
    ) {}
}
