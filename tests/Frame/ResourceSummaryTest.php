<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Rushing\DataFilters\Attributes\Filterable;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Operators\Exact;
use Rushing\DataFilters\Query\ResourceQuery;
use Rushing\DataFilters\Registry\ResourceDefinition as FilterDefinition;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Schemastud\Frame\Contracts\ResourceRegistry;
use Schemastud\Frame\FrameServiceProvider;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Summary\BeamResourceSummaryProvider;
use Splicewire\Beam\Tests\Entitlements\FakeEntitlementResolver;
use Splicewire\Beam\Tests\Fixtures\Backing\ArmRowData;
use Splicewire\Beam\Tests\Fixtures\Backing\ProbeComposite;
use Splicewire\Beam\Tests\TestCase;

/**
 * `GET frame/resources/{key}/summary` served by beam's default {@see BeamResourceSummaryProvider}
 * (realm-dashboards ticket 02).
 *
 * The property under test is the SCOPE-LEAK one: the `total` figure must equal the same actor's index
 * total on both list paths — the non-filterable path (the declaration's `scope` closure) and the
 * filterable path (the data-filters builder, owner-scoped and `filter[...]`-aware). A count taken off the
 * bare backing would pass a test that only seeded one owner; every fixture here seeds two.
 *
 * Frame's provider is booted for THIS class only, as `FrameResourcesIndexMembershipTest` explains: beam
 * boots without the frame rung (ADR-0082), but the summary route is frame's and the suite has to mount it.
 */
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

        // Non-filterable: the declaration's `scope` closure is the only row-level gate.
        $registry->register(new ParticleResource(
            key: 'scoped-widgets', backing: SummaryWidget::class, data: SummaryWidgetData::class,
            filterable: false, frame: true, readOnly: true, label: 'Scoped widgets', icon: 'box',
            scope: fn (Builder $q) => $q->where('owner_id', auth()->id()),
        ));

        // Filterable: the data-filters builder is the gate (owner-scoped in its base query).
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
            filterable: false, frame: true, readOnly: true, label: 'Streamed',
        ));

        // Realm-gated: operator membership, refused to a principal without the entitlement.
        $registry->register(new ParticleResource(
            key: 'gated-widgets', backing: SummaryWidget::class, data: SummaryWidgetData::class,
            filterable: false, frame: true, readOnly: true, label: 'Gated widgets',
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
    public function test_a_non_filterable_count_respects_the_declared_scope_and_equals_that_actors_index_total(): void
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
    public function test_a_filterable_count_rides_the_same_data_filters_builder_as_the_index(): void
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

    public function test_a_streams_only_backing_declines_and_the_route_answers_not_found(): void
    {
        $definition = $this->app->make(ResourceRegistry::class)->get('streamed');
        $this->assertNull($this->app->make(BeamResourceSummaryProvider::class)->summary($definition));

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
