<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Read;

use BadMethodCallException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;
use Splicewire\Beam\Concerns\PersistsSchemaRecord;
use Splicewire\Beam\Read\Cardinality;
use Splicewire\Beam\Read\Contracts\SchemaDataResolver;
use Splicewire\Beam\Read\NullSchemaDataResolver;
use Splicewire\Beam\Read\PayloadRecordReader;
use Splicewire\Beam\Read\ReadContext;
use Splicewire\Beam\Tests\TestCase;

/**
 * The degenerate read seam (beam-write-pipeline ticket 13): beam-core's payload reader resolves a
 * record's Data class through the {@see SchemaDataResolver} port and builds a typed Data from the
 * record's reconciled payload — with NO data-filters dependency. It proves the payoff at the seam: ONE
 * `ReadContext::includes` list compiles to the spatie serialization partial (a Lazy prop appears only
 * when included), and that list queries are (deliberately) the query-composing host binding's job.
 */
class PayloadRecordReaderTest extends TestCase
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

    private function reader(?string $dataClass): PayloadRecordReader
    {
        return new PayloadRecordReader(new class($dataClass) implements SchemaDataResolver
        {
            public function __construct(private ?string $class) {}

            public function dataClassFor(object $record): ?string
            {
                return $this->class;
            }
        });
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

    public function test_list_queries_are_the_host_bindings_job(): void
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

        // The beam-core default resolves nothing — a host must bind its projection policy.
        (new PayloadRecordReader(new NullSchemaDataResolver))->hydrate($record, ReadContext::detail());
    }

    public function test_cardinality_is_a_mode_on_the_context(): void
    {
        $this->assertSame(Cardinality::One, ReadContext::detail()->cardinality);
        $this->assertSame(Cardinality::Many, ReadContext::list()->cardinality);
    }
}

class ReaderFixtureModel extends Model
{
    use PersistsSchemaRecord;

    protected $table = 'reader_fixtures';

    protected $guarded = [];
}

class ReaderFixtureData extends Data
{
    public Lazy|string $extra;

    public function __construct(public string $title, ?string $extra = null)
    {
        // A Lazy include: default-excluded, appears only when the ReadContext asks for it.
        $this->extra = Lazy::create(fn () => $extra ?? 'lazy-'.$title);
    }
}
