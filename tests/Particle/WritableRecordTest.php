<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use ReflectionNamedType;
use Splicewire\Beam\Particle\Backing\EloquentBacking;
use Splicewire\Beam\Particle\Backing\ResolvedRecord;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;
use Splicewire\Beam\Particle\Backing\WritableRecord;
use Splicewire\Beam\Particle\Backing\WritesRecords;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\WriteSubjectNotEloquent;

/**
 * particle-write-surface 07 — `WritesRecords` must not name `Eloquent\Model` in its signatures.
 *
 * ## The ruling
 *
 * > A backing exists so data can be sourced from and written to any number of places — not just
 * > Eloquent models.
 *
 * The read side already honours it: {@see ResolvesRecord::resolve()}
 * returns a {@see ResolvedRecord} envelope, and every non-Eloquent backing in the estate (three, measured
 * 2026-08-31) implements exactly `ResolvesRecord, StreamsRecords` — the two capabilities whose signatures
 * a non-model backing can satisfy at all. `newRecord(): Model` is *unsatisfiable* for a backing over an
 * external system, so that census is a consequence of the signatures rather than a preference.
 *
 * ## What this pins, and what it deliberately does not
 *
 * It pins the CAPABILITY BOUNDARY: `WritesRecords` traffics in {@see WritableRecord}, whose subject is
 * `object`. It does NOT claim the write pipeline can persist a non-Eloquent subject — it cannot, and
 * ticket 07 scopes that out as a map (`WriteContext::$model`, `PersistStage::save()`, the gate's policy
 * subject, and `BeamParticlePersisted::$record` across 59 files in 6 packages and 7 hosts).
 *
 * That remaining requirement is collapsed into ONE named place — {@see WritableRecord::model()} — which
 * is the point of the envelope. The last test here asserts the refusal is a NAMED
 * {@see WriteSubjectNotEloquent} rather than a `TypeError`, because a named refusal is what makes the
 * partial migration honest instead of a lie moved one level up.
 */
class WritableRecordTest extends TestCase
{
    /** The defect, stated as a type assertion: no `Model` may appear in the capability's signatures. */
    public function test_writes_records_names_no_eloquent_model_in_its_signatures(): void
    {
        foreach (['resolveForWrite', 'newRecord'] as $method) {
            $return = (new ReflectionMethod(WritesRecords::class, $method))->getReturnType();

            $this->assertInstanceOf(ReflectionNamedType::class, $return);
            $this->assertNotSame(
                Model::class,
                $return->getName(),
                "WritesRecords::{$method}() still returns an Eloquent Model — the write capability is "
                .'bound to one persistence mechanism, so a backing over an external system cannot write.'
            );
            $this->assertSame(WritableRecord::class, $return->getName());
        }
    }

    /** The envelope mirrors {@see ResolvedRecord}: a subject plus a nullable, per-record schema ref. */
    public function test_the_envelope_carries_an_arbitrary_subject_and_a_nullable_per_record_schema_ref(): void
    {
        $subject = new \stdClass;

        $record = new WritableRecord($subject);
        $this->assertSame($subject, $record->subject);
        $this->assertNull($record->schemaRef);

        $this->assertSame('intake/waitlist', (new WritableRecord($subject, 'intake/waitlist'))->schemaRef);
    }

    /** `EloquentBacking` keeps working: same model, same fresh instance — only the return is wrapped. */
    public function test_eloquent_backing_still_yields_the_declared_model_through_the_envelope(): void
    {
        Schema::create('writable_record_fixtures', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        $backing = new EloquentBacking(WritableRecordFixtureModel::class);

        $fresh = $backing->newRecord();

        $this->assertInstanceOf(WritableRecord::class, $fresh);
        $this->assertInstanceOf(WritableRecordFixtureModel::class, $fresh->model());
        $this->assertFalse($fresh->model()->exists);

        // resolveForWrite() over a missing id is still a null RESOLUTION, not a WritableRecord wrapping null.
        // ⚠️ A NUMERIC absent id, not a string one: this fixture has an integer PK, and on Postgres a
        // non-numeric id would throw QueryException rather than return null. The suite is sqlite today.
        $this->assertNull($backing->resolveForWrite('999999', []));

        // …and over a real id it resolves the SAME row it always did — the ~127 `backing: SomeModel::class`
        // declarations must be behaviourally untouched by 07.
        $existing = WritableRecordFixtureModel::query()->create([]);
        $resolved = $backing->resolveForWrite((string) $existing->getKey(), []);

        $this->assertInstanceOf(WritableRecord::class, $resolved);
        $this->assertTrue($resolved->model()->is($existing));
        $this->assertNull($resolved->schemaRef);
    }

    /** The one remaining Eloquent requirement refuses BY NAME, so the gap is legible rather than a TypeError. */
    public function test_a_non_eloquent_subject_refuses_by_name_at_the_single_remaining_seam(): void
    {
        $record = new WritableRecord(new \stdClass);

        $this->expectException(WriteSubjectNotEloquent::class);
        $this->expectExceptionMessageMatches('/stdClass/');

        $record->model();
    }
}

class WritableRecordFixtureModel extends Model
{
    protected $table = 'writable_record_fixtures';
}
