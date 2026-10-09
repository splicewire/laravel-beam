<?php

namespace Splicewire\Beam\Tests\Read;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Rushing\DataFilters\Query\ResourceQuery;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;
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
 * projection seam: `ReadContext::includes` controls the spatie serialization partial, so a Lazy
 * property appears only when included. Query composition is tested with ParticleListQuery.
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

    public function test_explicit_headless_identity_selects_payload_projection_in_either_registration_order(): void
    {
        foreach ([false, true] as $reverse) {
            $registry = new ParticleResourceRegistry;
            $resources = [new ParticleResource(key: 'wrong', backing: ReaderFixtureModel::class, data: ReaderFixtureData::class, label: 'First'),
                new ParticleResource(key: 'chosen', backing: ReaderFixtureModel::class, data: SecondReaderFixtureData::class, schemaRef: 'https://beam.test/schemas/reader/1')];
            foreach ($reverse ? array_reverse($resources) : $resources as $resource) {
                $registry->register($resource);
            }
            $record = ReaderFixtureModel::create(['schema_ref' => 'https://beam.test/schemas/reader/7', 'payload' => ['title' => 'Payload']]);
            $record->setAttribute('title', 'Model decoy');
            $reader = new PayloadParticleReader($registry);
            $data = $reader->hydrate($record, ReadContext::detail(['extra']));
            $this->assertInstanceOf(SecondReaderFixtureData::class, $data);
            $this->assertSame(['title' => 'Payload', 'extra' => 'lazy-Payload'], $data->toArray());
            $record->schema_ref = 'https://beam.test/schemas/unknown';
            try {
                $reader->hydrate($record, ReadContext::detail());
                $this->fail('Unknown explicit identity cannot use a model projection.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('No Data class resolved', $exception->getMessage());
            }
        }
    }

    public function test_ambiguous_plain_record_never_selects_first_projection(): void
    {
        $registry = new ParticleResourceRegistry;
        foreach (['first' => ReaderFixtureData::class, 'second' => SecondReaderFixtureData::class] as $key => $data) {
            $registry->register(new ParticleResource(key: $key, backing: ReaderFixtureModel::class, data: $data, label: $key));
        }
        $this->expectException(RuntimeException::class);
        (new PayloadParticleReader($registry))->hydrate(ReaderFixtureModel::create(['payload' => ['title' => 'Ambiguous']]), ReadContext::detail());
    }

    public function test_it_refuses_a_string_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->reader(ReaderFixtureData::class)->hydrate('reader/1', ReadContext::detail());
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

class SecondReaderFixtureData extends ReaderFixtureData {}
