<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\Contribution\ResourceContribution;
use Splicewire\Beam\Particle\Contribution\ResourceContributionRegistry;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleListQuery;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * The particle contribution seam (particle-contribution-seam ticket 04, landed by ticket 15).
 *
 * ONE package owns a resource; ANOTHER contributes a named slice of its read projection without either
 * naming the other's symbols. The proving case in the estate is commerce → `tenants`; the fixtures here
 * are its shape with the domain removed: a `crate` resource owned by one "package", and a `weights`
 * slice contributed by another.
 *
 * The invariants under test are the ones the ticket's acceptance list names, plus the two-transport
 * symmetry — a contributed key must be on the wire identically over REST and Frame, because the ONE
 * shared projector is the whole reason there are not two folds to hand-sync.
 */
class ResourceContributionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('contrib_crates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('contrib_weights', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contrib_crate_id');
            $table->string('period');
            $table->integer('grams');
        });

        foreach (['alpha', 'beta'] as $name) {
            $crate = ContribCrate::create(['name' => $name]);
            ContribWeight::create(['contrib_crate_id' => $crate->id, 'period' => '2026-07', 'grams' => 10]);
            ContribWeight::create(['contrib_crate_id' => $crate->id, 'period' => '2026-08', 'grams' => 99]);
        }

        // The OWNER's declaration — it names no contributor and pre-declares no hook, which is the point.
        // `label:` makes it framed so BOTH transports serve this one declaration.
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'contrib-crate',
            backing: ContribCrate::class,
            data: ContribCrateData::class,
            filterable: false,
            label: 'Crates',
        ));
    }

    private function contributions(): ResourceContributionRegistry
    {
        return $this->app->make(ResourceContributionRegistry::class);
    }

    /** The ordinary contribution: a static include plus a value arm. */
    private function contributeWeights(): void
    {
        $this->contributions()->register(new ResourceContribution(
            key: 'contrib-crate',
            as: 'weights',
            data: ContribWeightsData::class,
            includes: ['weights'],
            value: fn (Model $record, $ctx, array $filters) => new ContribWeightsData(
                total: (int) $record->weights->sum('grams'),
            ),
        ));
    }

    private function restRow(array $query = []): array
    {
        $request = Request::create('/crates', 'GET', $query);
        $route = new Route('GET', '/crates', []);
        $route->defaults(ParticleController::RESOURCE, 'contrib-crate');
        $request->setRouteResolver(fn () => $route);

        $response = $this->app->make(ParticleController::class)->index($request)->toResponse($request);

        return json_decode($response->getContent(), true)['data'][0];
    }

    private function frameRow(): array
    {
        return $this->app->make(ParticleFrameResourceHandler::class)->index(
            $this->app->make(ParticleResourceRegistry::class)->definition('contrib-crate'),
            [],
        )[0];
    }

    // ── The null case: absent ⟺ not installed, present-and-null ⟺ ran and returned null ─────────────

    public function test_a_resource_nobody_contributes_to_has_no_contributed_key_at_all(): void
    {
        // Acceptance: a host with the owner but not the contributor gets a clean projection — not a key
        // holding null. The distinction is the whole of ticket 04 §A7, and it is what the
        // AuthUserExtras port this seam supersedes could never express.
        $this->assertArrayNotHasKey('weights', $this->restRow());
        $this->assertArrayNotHasKey('weights', $this->frameRow());
    }

    public function test_a_contribution_that_returns_null_contributes_the_key_holding_null(): void
    {
        $this->contributions()->register(new ResourceContribution(
            key: 'contrib-crate',
            as: 'weights',
            data: ContribWeightsData::class,
            value: fn () => null,
        ));

        $row = $this->restRow();

        $this->assertArrayHasKey('weights', $row);
        $this->assertNull($row['weights']);
    }

    public function test_an_includes_only_contribution_contributes_no_key(): void
    {
        // No value arm ⇒ it wants the relation eager-loaded and nothing on the wire. Contributing a null
        // key here would destroy the absent-vs-null distinction for every OTHER contributor.
        $this->contributions()->register(new ResourceContribution(
            key: 'contrib-crate',
            as: 'weights',
            data: ContribWeightsData::class,
            includes: ['weights'],
        ));

        $this->assertArrayNotHasKey('weights', $this->restRow());
    }

    // ── Conflict semantics: Reject, deliberately not Supersede ──────────────────────────────────────

    public function test_two_contributions_claiming_one_sub_projection_key_throw_at_registration(): void
    {
        $this->contributeWeights();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contrib-crate.weights');

        $this->contributions()->register(new ResourceContribution(
            key: 'contrib-crate',
            as: 'weights',
            data: ContribWeightsData::class,
            value: fn () => null,
        ));
    }

    public function test_two_contributions_under_different_keys_compose(): void
    {
        $this->contributeWeights();
        $this->contributions()->register(new ResourceContribution(
            key: 'contrib-crate',
            as: 'tally',
            data: ContribWeightsData::class,
            value: fn () => new ContribWeightsData(total: 1),
        ));

        $row = $this->restRow();

        $this->assertSame(109, $row['weights']['total']);
        $this->assertSame(1, $row['tally']['total']);
    }

    // ── The value arm, over BOTH transports off the ONE shared projector ────────────────────────────

    public function test_a_contributed_slice_lands_on_the_row_over_both_transports(): void
    {
        $this->contributeWeights();

        $this->assertSame(109, $this->restRow()['weights']['total']);
        $this->assertSame(109, $this->frameRow()['weights']['total']);
    }

    public function test_a_value_arm_returning_the_wrong_type_throws_rather_than_reaching_the_wire(): void
    {
        $this->contributions()->register(new ResourceContribution(
            key: 'contrib-crate',
            as: 'weights',
            data: ContribWeightsData::class,
            value: fn () => new ContribCrateData(id: 1, name: 'not-a-slice'),
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contrib-crate.weights');

        $this->restRow();
    }

    // ── The includes arm: static folds in get(), dynamic resolves per request ───────────────────────

    public function test_contributed_static_includes_fold_into_the_owners_declaration(): void
    {
        $this->contributeWeights();

        $resource = $this->app->make(ParticleResourceRegistry::class)->get('contrib-crate');

        // The OWNER declared none. `get()` is where both transports read `includes`, which is why the
        // fold is there and not on the manifest-path projection beside it.
        $this->assertSame(['weights'], $resource->includes);
    }

    public function test_the_stored_declaration_is_not_mutated_by_the_fold(): void
    {
        $this->contributeWeights();

        $registry = $this->app->make(ParticleResourceRegistry::class);
        $registry->get('contrib-crate');

        // A second lookup must not accumulate. The fold clones; it never writes back.
        $this->assertSame(['weights'], $registry->get('contrib-crate')->includes);
    }

    public function test_a_contributed_include_is_eager_loaded_on_the_list_path(): void
    {
        $this->contributeWeights();

        DB::enableQueryLog();
        $this->restRow();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // ONE query against the contributed relation for the whole page, not one per row — the reason
        // the includes arm exists at all. The value arm is a per-row call site by accepted design; its
        // RELATIONS are what this keeps off that path.
        $weightQueries = array_filter($queries, fn (array $q) => str_contains($q['query'], 'contrib_weights'));

        $this->assertCount(1, $weightQueries);
    }

    public function test_the_dynamic_includes_arm_reads_the_request_facet_bag(): void
    {
        // The one job a static `list<string>` provably cannot do: a request-parameterized CONSTRAINED
        // eager-load. This is `billStatus`/`billTotal` honouring `filter[period]`, with the domain removed.
        $this->contributions()->register(new ResourceContribution(
            key: 'contrib-crate',
            as: 'weights',
            data: ContribWeightsData::class,
            includes: fn (array $filters) => [
                'weights' => fn ($q) => $q->where('period', $filters['period'] ?? '2026-07'),
            ],
            value: fn (Model $record) => new ContribWeightsData(total: (int) $record->weights->sum('grams')),
        ));

        // The facet selects the 99g period; absent it the contribution's own default selects the 10g one.
        $this->assertSame(99, $this->restRow(['filter' => ['period' => '2026-08']])['weights']['total']);
        $this->assertSame(10, $this->restRow()['weights']['total']);
    }

    public function test_the_dynamic_arm_is_not_folded_into_the_pure_declaration_lookup(): void
    {
        $this->contributions()->register(new ResourceContribution(
            key: 'contrib-crate',
            as: 'weights',
            data: ContribWeightsData::class,
            includes: fn (array $filters) => ['weights'],
        ));

        // `get(string $key)` has no request to resolve a facet bag against, and inventing one there is
        // how a per-request closure quietly becomes a per-record one. It resolves in ParticleListQuery.
        $this->assertSame([], $this->app->make(ParticleResourceRegistry::class)->get('contrib-crate')->includes);
        $this->assertArrayHasKey(
            'weights',
            (new ParticleListQuery)->forList(
                $this->app->make(ParticleResourceRegistry::class)->get('contrib-crate'),
            )->getEagerLoads(),
        );
    }
}

class ContribCrate extends Model
{
    protected $table = 'contrib_crates';

    protected $guarded = [];

    public function weights(): HasMany
    {
        return $this->hasMany(ContribWeight::class, 'contrib_crate_id');
    }
}

class ContribWeight extends Model
{
    protected $table = 'contrib_weights';

    protected $guarded = [];

    public $timestamps = false;
}

/** The OWNER's projection — it carries no contributed property, and structurally must not. */
class ContribCrateData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}

/** The CONTRIBUTOR's slice — its own Data class, shipped by the contributing package. */
class ContribWeightsData extends Data
{
    public function __construct(
        public int $total,
    ) {}
}
