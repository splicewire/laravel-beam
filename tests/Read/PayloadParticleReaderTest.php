<?php

namespace Splicewire\Beam\Tests\Read;

use BadMethodCallException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Rushing\DataFilters\Facades\DataFilter;
use Rushing\DataFilters\Query\ResourceQuery;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;
use Spatie\QueryBuilder\QueryBuilder;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Read\Cardinality;
use Splicewire\Beam\Read\Contracts\ReadStage;
use Splicewire\Beam\Read\PayloadParticleReader;
use Splicewire\Beam\Read\ReadContext;
use Splicewire\Beam\Read\ReadPass;
use Splicewire\Beam\Read\Stages\ProjectStage;
use Splicewire\Beam\Tests\TestCase;

/**
 * The degenerate read seam (beam-write-pipeline ticket 13): beam-core's payload reader resolves a
 * record's Data class straight off beam's {@see ParticleResourceRegistry} (ADR-0156 retired the
 * SchemaDataResolver port) and builds a typed Data from the record's reconciled payload. It proves the
 * payoff at the seam: ONE `ReadContext::includes` list compiles to BOTH the spatie serialization partial
 * (a Lazy prop appears only when included) AND the eager-load axis of the composed data-filters builder.
 *
 * ⚠️ This docblock used to end "list queries are (deliberately) the query-composing host binding's job".
 * They are not, since particle-manifest-repatriation ticket 10 — see
 * {@see PayloadParticleReader::query()} for why the default was wrong and what stayed the same.
 */
class PayloadParticleReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('reader_fixtures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * A reader over a registry that maps {@see ReaderFixtureModel} to $dataClass. Passing null yields an
     * empty registry (nothing claims the record — the "no Data class resolves" path).
     */
    private function reader(?string $dataClass): PayloadParticleReader
    {
        $registry = new ParticleResourceRegistry;

        if ($dataClass !== null) {
            $registry->register(new ParticleResource(
                key: 'reader-fixture',
                backing: ReaderFixtureModel::class,
                data: $dataClass,
                label: 'Reader Fixture',
            ));
        }

        return new PayloadParticleReader($registry);
    }

    public function test_it_hydrates_a_typed_data_from_the_records_payload(): void
    {
        $record = ReaderFixtureModel::create(['payload' => ['title' => 'Hello']]);

        $data = $this->reader(ReaderFixtureData::class)->hydrate($record, ReadContext::detail());

        $this->assertInstanceOf(ReaderFixtureData::class, $data);
        $this->assertSame('Hello', $data->title);
        // A Lazy include is excluded by default — the one-include-list has not asked for it.
        $this->assertSame(['title' => 'Hello'], $data->toArray());
    }

    public function test_the_include_list_compiles_to_the_serialization_partial(): void
    {
        $record = ReaderFixtureModel::create(['payload' => ['title' => 'Hello']]);

        $data = $this->reader(ReaderFixtureData::class)->project($record, ReadContext::detail(['extra']));

        // The single includes list drove the serialization partial: the Lazy prop now serializes.
        $this->assertEqualsCanonicalizing(['title' => 'Hello', 'extra' => 'lazy-Hello'], $data->toArray());
    }

    /**
     * The reader COMPOSES a list query now (particle-manifest-repatriation ticket 10). Two unrelated
     * hosts — `~/Herd/splicewire-app` (through tower's `DataFilterRecordHydrator`) and `~/Herd/audiostud`
     * (through a six-line subclass of this class) — had independently replaced the default with the same
     * six lines, and the reason the default did not carry them ("needs NO data-filters dependency") was
     * stale: `rushing/laravel-data-filters` is a hard `require` of beam, and beam's own
     * `declareFilterResources()` registers a data-filters resource for its own `hooks` particle.
     */
    public function test_it_composes_the_data_filters_builder_for_a_registered_resource(): void
    {
        DataFilter::resource('reader-fixture', [
            'data' => ReaderFixtureData::class,
            'query' => ReaderFixtureResourceQuery::class,
            'model' => ReaderFixtureModel::class,
        ]);

        $builder = $this->reader(ReaderFixtureData::class)
            ->query('reader-fixture', ReadContext::list(['extra']));

        $this->assertInstanceOf(QueryBuilder::class, $builder);
        // The ONE includes list drove the EAGER-LOAD axis here, exactly as it drives the
        // serialization partial in project() — that is the whole payoff of the seam.
        $this->assertArrayHasKey('extra', $builder->getEagerLoads());
    }

    /**
     * The degradation contract is UNCHANGED: a key with no data-filters resource behind it still raises
     * `BadMethodCallException`, which is what `ParticleFrameResourceHandler::indexQuery()` catches to fall
     * back to the plain query. Only the *reason* moved — from "this reader never composes" to "there is
     * nothing registered under this key", which is the condition tower's binding already stated.
     */
    public function test_it_raises_when_no_data_filters_resource_is_registered(): void
    {
        $this->expectException(BadMethodCallException::class);

        $this->reader(ReaderFixtureData::class)->query('reader_fixtures', ReadContext::list());
    }

    public function test_it_refuses_a_non_model_source(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->reader(ReaderFixtureData::class)->hydrate(['title' => 'x'], ReadContext::detail());
    }

    public function test_it_throws_when_no_data_class_resolves(): void
    {
        $record = ReaderFixtureModel::create(['payload' => ['title' => 'Hello']]);

        $this->expectException(RuntimeException::class);

        // An empty registry claims no record type — nothing resolves, so projection throws.
        $this->reader(null)->hydrate($record, ReadContext::detail());
    }

    public function test_cardinality_is_a_mode_on_the_context(): void
    {
        $this->assertSame(Cardinality::One, ReadContext::detail()->cardinality);
        $this->assertSame(Cardinality::Many, ReadContext::list()->cardinality);
    }

    public function test_a_host_composes_a_stage_after_projection(): void
    {
        $record = ReaderFixtureModel::create(['payload' => ['title' => 'Secret']]);

        // A host inserts a redaction pipe AFTER the shipped ProjectStage — the fine-grained read seam
        // (DESIGN §9d). The stage receives the built Data on the pass and transforms it.
        $registry = new ParticleResourceRegistry;
        $registry->register(new ParticleResource(
            key: 'reader-fixture',
            backing: ReaderFixtureModel::class,
            data: ReaderFixtureData::class,
            label: 'Reader Fixture',
        ));

        $redact = new class implements ReadStage
        {
            public function handle(ReadPass $pass, \Closure $next): ReadPass
            {
                $pass->data->title = 'REDACTED';

                return $next($pass);
            }
        };

        $reader = new PayloadParticleReader($registry, stages: [
            new ProjectStage($registry),
            $redact,
        ]);

        $data = $reader->hydrate($record, ReadContext::detail());

        $this->assertSame('REDACTED', $data->title);
    }
}

class ReaderFixtureModel extends Model
{
    use PersistsBeamParticle;

    protected $table = 'reader_fixtures';

    protected $guarded = [];
}

/**
 * The data-filters wiring behind `reader-fixture` — a bare {@see ResourceQuery}, which is what a host
 * registers when it wants the declared filter/sort/include surface and no extra row-level scoping.
 */
class ReaderFixtureResourceQuery extends ResourceQuery {}

class ReaderFixtureData extends Data
{
    public Lazy|string $extra;

    public function __construct(public string $title, ?string $extra = null)
    {
        // A Lazy include: default-excluded, appears only when the ReadContext asks for it.
        $this->extra = Lazy::create(fn () => $extra ?? 'lazy-'.$title);
    }
}
