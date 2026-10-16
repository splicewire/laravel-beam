<?php

namespace Splicewire\Beam\Tests\Collections;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Collections\CollectionDefinition;
use Splicewire\Beam\Collections\CollectionUniqueWrite;
use Splicewire\Beam\Collections\StandaloneRecordQuery;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Tests\TestCase;

class CollectionUniqueWriteTest extends TestCase
{
    private const STEM = 'https://example.test/schemas/article';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create(Beam::table('schemas'), function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('schema_name');
            $table->integer('version');
        });
        Schema::create(Beam::table('particles'), function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('schema_ref');
            $table->string('source_tier')->default('local');
            $table->json('payload');
        });
    }

    private function definition(bool $unique = true): CollectionDefinition
    {
        return new CollectionDefinition([
            '$id' => self::STEM.'/1', 'type' => 'object',
            'x-beam-collection' => ['label' => 'Articles', 'nameField' => 'slug'],
            'properties' => ['slug' => ['type' => 'string', ...($unique ? ['x-beam-unique' => true] : [])]],
        ]);
    }

    private function anchor(): void
    {
        DB::table(Beam::table('schemas'))->insert(['id' => 'anchor', 'schema_name' => self::STEM, 'version' => 1]);
    }

    private function insert(string $id, array $payload, string $tier = 'local'): void
    {
        DB::table(Beam::table('particles'))->insert(['id' => $id, 'schema_ref' => self::STEM.'/1', 'source_tier' => $tier, 'payload' => json_encode($payload)]);
    }

    private function runWrite(array $payload, \Closure $persist, ?BeamParticle $model = null, bool $unique = true): mixed
    {
        return (new CollectionUniqueWrite(app(StandaloneRecordQuery::class)))->run(
            $model ?? new BeamParticle, $this->definition($unique), [self::STEM, self::STEM.'/1'], $payload, $persist,
        );
    }

    public function test_duplicate_refuses_before_persist(): void
    {
        $this->anchor();
        $this->insert('existing', ['slug' => 'same']);
        $this->expectException(ValidationException::class);
        $this->runWrite(['slug' => 'same'], fn () => $this->fail('Duplicate persisted.'));
    }

    public function test_self_update_is_excluded(): void
    {
        $this->anchor();
        $this->insert('self', ['slug' => 'same']);
        $model = new BeamParticle;
        $model->setRawAttributes(['id' => 'self']);
        $model->exists = true;
        $this->assertSame('updated', $this->runWrite(['slug' => 'same'], fn () => 'updated', $model));
    }

    public function test_missing_anchor_refuses_instead_of_claiming_safe_uniqueness(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('registered database schema anchor');
        $this->runWrite(['slug' => 'new'], fn () => $this->fail('Unprotected write persisted.'));
    }

    public function test_absent_schema_table_refuses_with_an_explanation(): void
    {
        Schema::drop(Beam::table('schemas'));
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('registered database schema anchor');
        $this->runWrite(['slug' => 'new'], fn () => $this->fail('Unprotected write persisted.'));
    }

    public function test_non_unique_collection_needs_no_anchor_or_transaction(): void
    {
        $this->assertSame(0, $this->runWrite(['slug' => 'new'], fn () => DB::connection()->transactionLevel(), unique: false));
    }

    public function test_persist_runs_inside_transaction_and_failure_rolls_back(): void
    {
        $this->anchor();
        try {
            $this->runWrite(['slug' => 'new'], function (): void {
                $this->assertGreaterThan(0, DB::connection()->transactionLevel());
                $this->insert('new', ['slug' => 'new']);
                throw new \RuntimeException('after-persist failure');
            });
            $this->fail('Expected failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('after-persist failure', $e->getMessage());
        }
        $this->assertSame(0, DB::table(Beam::table('particles'))->count());
    }

    public function test_shadows_do_not_claim_a_local_unique_value(): void
    {
        $this->anchor();
        $this->insert('shadow', ['slug' => 'same'], 'shadow-cached');
        $this->assertSame('created', $this->runWrite(['slug' => 'same'], fn () => 'created'));
    }
}
