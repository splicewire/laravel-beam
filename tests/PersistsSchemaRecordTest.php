<?php

namespace Splicewire\Beam\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Concerns\PersistsSchemaRecord;

class PersistsSchemaRecordTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('schema_record_fixtures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_assigns_a_time_ordered_uuid7_primary_key(): void
    {
        $a = SchemaRecordFixture::create(['schema_ref' => 'x/1', 'payload' => ['a' => 1]]);
        $b = SchemaRecordFixture::create(['schema_ref' => 'x/2', 'payload' => ['a' => 2]]);

        // uuid, not an auto-increment int
        $this->assertIsString($a->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $a->id);
        // uuid7 is time-ordered: a later row sorts after an earlier one
        $this->assertTrue($b->id > $a->id, 'uuid7 keys should be time-ordered');
    }

    public function test_it_casts_payload_and_meta_to_arrays(): void
    {
        $r = SchemaRecordFixture::create([
            'schema_ref' => 'x/1',
            'payload' => ['nested' => ['k' => 'v']],
            'meta' => ['derived' => true],
        ]);

        $fresh = SchemaRecordFixture::find($r->id);
        $this->assertSame(['nested' => ['k' => 'v']], $fresh->payload);
        $this->assertSame(['derived' => true], $fresh->meta);
    }

    public function test_extract_is_an_inert_seam_by_default(): void
    {
        $r = new SchemaRecordFixture;
        $this->assertNull($r->extract());
    }
}

class SchemaRecordFixture extends Model
{
    use PersistsSchemaRecord;

    protected $table = 'schema_record_fixtures';

    protected $guarded = [];
}
