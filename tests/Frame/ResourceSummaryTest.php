<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Operators\Exact;
use Rushing\DataFilters\Query\ResourceQuery;
use Rushing\DataFilters\Registry\ResourceDefinition as FilterDefinition;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Routing\ResourceRoutes;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Entitlements\FakeEntitlementResolver;
use Splicewire\Beam\Tests\Fixtures\Backing\ArmRowData;
use Splicewire\Beam\Tests\Fixtures\Backing\ProbeComposite;
use Splicewire\Beam\Tests\TestCase;

class ResourceSummaryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FrameServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // `entitlement:{key}` abilities are defined at BOOT from the known key universe.
        $app['config']->set('app.entitlements', ['os.operate' => []]);
        $app['config']->set('beam.core.realm_gates', [
            'operator' => ['entitlement' => 'os.operate', 'mode' => 'hard'],
        ]);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('frame.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('summary_widgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
            $table->integer('count');
        });

        SummaryWidget::create(['owner_id' => 1, 'name' => 'mine-a', 'count' => 5]);
        SummaryWidget::create(['owner_id' => 1, 'name' => 'mine-b', 'count' => 7]);
        SummaryWidget::create(['owner_id' => 2, 'name' => 'theirs', 'count' => 5]);

        $registry = $this->app->make(ParticleResourceRegistry::class);

        // The declaration's scope closure supplies this resource's row boundary.
        $registry->register(new ParticleResource(
            key: 'scoped-widgets', backing: SummaryWidget::class, data: SummaryWidgetData::class,
            frame: true, readOnly: true, label: 'Scoped widgets', icon: 'box',
            scope: fn (Builder $q) => $q->where('owner_id', auth()->id()),
        ));

        // The query declaration supplies this resource's owner boundary.
        $registry->register(new ParticleResource(
            key: 'filtered-widgets', backing: SummaryWidget::class, data: SummaryWidgetData::class,
            frame: true, readOnly: true, label: 'Filtered widgets',
        ));
        DataFilter::registry()->registerDefinition(new FilterDefinition(
            key: 'filtered-widgets', data: SummaryWidgetData::class, query: OwnerScopedWidgetQuery::class, model: SummaryWidget::class,
        ));

        // Streams-only: a composite has no builder to count through.
        $registry->register(new ParticleResource(
            key: 'streamed', backing: ProbeComposite::class, data: ArmRowData::class,
            frame: true, readOnly: true, label: 'Streamed',
        ));

        // Realm-VARYING: the scope closure READS its second argument, so the same declaration answers a
        // different set per realm. Owner 1 holds two rows, owner 2 holds one — the counts differ, which is
        // the only shape in which "the realm reached the closure" is observable from outside.
        $registry->register(new ParticleResource(
            key: 'realm-widgets', backing: SummaryWidget::class, data: SummaryWidgetData::class,
            frame: true, readOnly: true, label: 'Realm widgets',
            scope: fn (Builder $q, ?string $realm) => $q->where('owner_id', $realm === 'alpha' ? 1 : 2),
        ), ['alpha', 'beta']);

        // Realm-gated: operator membership, refused to a principal without the entitlement.
        $registry->register(new ParticleResource(
            key: 'gated-widgets', backing: SummaryWidget::class, data: SummaryWidgetData::class,
            frame: true, readOnly: true, label: 'Gated widgets',
        ), ['operator']);
    }

    private function actor(int $id): User
    {
        return (new User)->forceFill(['id' => $id]);
    }

    private function holding(array $keys): void
    {
        $this->app->instance(EntitlementResolver::class, new FakeEntitlementResolver($keys));
    }

    /** The `total` figure and the index total for the same actor and query string, side by side. */
    private function figureAndIndexTotal(string $key, string $query = ''): array
    {
        $summary = $this->getJson("frame/resources/{$key}/summary{$query}")->assertOk()->json();
        $index = $this->getJson("frame/resources/{$key}{$query}")->assertOk()->json();

        return [$summary['figures'][0]['value'], $index['total']];
    }

    public function test_the_default_provider_answers_the_resource_label_icon_and_one_total_figure(): void
    {
        $this->actingAs($this->actor(1))->getJson('frame/resources/scoped-widgets/summary')->assertOk()->assertExactJson([
            'key' => 'scoped-widgets',
            'label' => 'Scoped widgets',
            'icon' => 'box',
            'figures' => [['key' => 'total', 'label' => 'Scoped widgets', 'value' => 2, 'tone' => null]],
            'overview' => null,
        ]);
    }

    /** The scope-leak test: the declared `scope()` hook narrows the count exactly as it narrows the index. */
    public function test_a_declared_scope_count_respects_the_declared_scope_and_equals_that_actors_index_total(): void
    {
        $this->assertSame(3, SummaryWidget::count(), 'The unscoped table must differ from every actor\'s reach, or this test cannot fail.');

        $this->actingAs($this->actor(1));
        [$figure, $total] = $this->figureAndIndexTotal('scoped-widgets');
        $this->assertSame(2, $figure);
        $this->assertSame($total, $figure);

        $this->actingAs($this->actor(2));
        [$figure, $total] = $this->figureAndIndexTotal('scoped-widgets');
        $this->assertSame(1, $figure);
        $this->assertSame($total, $figure);
    }

    /** The other list path: the data-filters builder's owner scope AND the request's `filter[...]` both hold. */
    public function test_a_declared_query_count_rides_the_same_data_filters_builder_as_the_index(): void
    {
        $this->actingAs($this->actor(1));
        [$figure, $total] = $this->figureAndIndexTotal('filtered-widgets');
        $this->assertSame(2, $figure);
        $this->assertSame($total, $figure);

        [$figure, $total] = $this->figureAndIndexTotal('filtered-widgets', '?filter[count]=5');
        $this->assertSame(1, $figure);
        $this->assertSame($total, $figure);

        $this->actingAs($this->actor(2));
        [$figure, $total] = $this->figureAndIndexTotal('filtered-widgets', '?filter[count]=5');
        $this->assertSame(1, $figure);
        $this->assertSame($total, $figure);
    }

    /**
     * The realm actually reaches the declared `scope` closure's SECOND argument.
     *
     * Every other test here would pass unchanged if the closure were called with `null` in that slot — they
     * all declare closures that ignore it. This one does not: the same resource, mounted under two realms,
     * must answer two different totals, so a summary counted with the realm dropped is a failing test rather
     * than a silently global tile.
     */
    public function test_the_declared_scope_receives_the_mounted_realm_and_the_figure_differs_per_realm(): void
    {
        foreach (['alpha', 'beta'] as $realm) {
            Route::prefix($realm)->group(function () use ($realm): void {
                ResourceRoutes::summary(at: 'resources/{resource}', names: $realm.'.resources', defaults: ['realm' => $realm]);
            });
        }

        $this->actingAs($this->actor(1));

        $this->getJson('alpha/resources/realm-widgets/summary')->assertOk()->assertJsonPath('figures.0.value', 2);
        $this->getJson('beta/resources/realm-widgets/summary')->assertOk()->assertJsonPath('figures.0.value', 1);
    }

    public function test_a_streams_only_backing_declines_and_the_route_answers_not_found(): void
    {
        $this->actingAs($this->actor(1));
        $this->getJson('frame/resources/streamed')->assertOk();
        $this->getJson('frame/resources/streamed/summary')->assertNotFound();
    }

    /** The summary opens no new door: it is refused across resource reach exactly as `filters/schema` is. */
    public function test_the_summary_is_refused_to_a_principal_outside_the_resources_realm_gate(): void
    {
        $this->actingAs($this->actor(1));

        $this->holding([]);
        $this->getJson('frame/resources/gated-widgets/summary')->assertForbidden();
        $this->getJson('frame/resources/gated-widgets/filters/schema')->assertForbidden();

        $this->holding(['os.operate']);
        $this->getJson('frame/resources/gated-widgets/summary')->assertOk()->assertJsonPath('figures.0.value', 3);
    }
}

class SummaryWidget extends Model
{
    public $timestamps = false;

    protected $table = 'summary_widgets';

    protected $guarded = [];
}

class SummaryWidgetData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        #[Filterable(Exact::class)]
        public int $count,
    ) {}
}

class OwnerScopedWidgetQuery extends ResourceQuery
{
    protected function baseQuery(Request $request): Builder
    {
        return SummaryWidget::query()->where('owner_id', $request->user()?->getAuthIdentifier());
    }
}
