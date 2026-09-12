<?php

namespace Splicewire\Beam\Tests\Schema;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Tests\TestCase;

/**
 * The repair path for an ALREADY-MIGRATED host: `widen_beam_hook_morph_ids_to_string.php.stub` run
 * against the bigint shape `nullableMorphs()` used to emit (ux-demo-convergence
 * G3-FLAGSHIP-HOOKS-GUEST-LINKS).
 *
 * {@see SharedMigrationStubsConvergeTest} already proves the ALTER is silent on a host with no table
 * and a true no-op on one built from the current create. This class proves the half neither of those
 * can reach — that it actually CONVERTS, keeps the rows and keeps the index — and it starts from a
 * hand-written legacy `create`, because the shipped create no longer produces the shape under
 * repair. That hand-written table is the one thing here that could rot, so
 * {@see test_the_legacy_shape_is_what_nullable_morphs_still_emits} pins it against the framework
 * rather than against a memory of it.
 *
 * The Postgres half is {@see BeamHookMorphWideningPostgresTest}, which is where the defect is
 * actually observable: sqlite's type affinity accepts a uuid into an `integer` column without
 * complaint, so the 500 this repair exists to end CANNOT be reproduced on the test driver. A green
 * sqlite run here is the mechanism working, not the defect proved gone.
 */
class BeamHookMorphWideningTest extends TestCase
{
    protected function tearDown(): void
    {
        // `$this->app` is null when a subclass skipped before the application booted (the Postgres
        // lane with no database offered). `Beam::table()` resolves through the container, so the
        // guard is what keeps a skip from being reported as an error.
        if ($this->app !== null) {
            Schema::dropIfExists(Beam::table('hooks'));
        }

        parent::tearDown();
    }

    /** The stub under test. */
    protected function widening(): Migration
    {
        return require dirname(__DIR__, 2).'/database/migrations/shared/widen_beam_hook_morph_ids_to_string.php.stub';
    }

    protected function create(): Migration
    {
        return require dirname(__DIR__, 2).'/database/migrations/shared/create_beam_hooks_table.php.stub';
    }

    /**
     * The table as hosts migrated before 2026-09-12 have it: the current create with both morph
     * pairs back in their `nullableMorphs()` spelling.
     */
    protected function createLegacyTable(): void
    {
        Schema::create(Beam::table('hooks'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('endpoint');
            $table->string('secret');
            $table->string('token')->nullable();
            $table->json('events');
            $table->nullableMorphs('subject');
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->uuid('last_failure_request_log_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->json('entitlement_keys')->nullable();
            $table->nullableMorphs('owner');
            $table->timestamps();
        });
    }

    /** @return array<string, string> column name => driver type name, lowercased */
    protected function types(): array
    {
        $types = [];

        foreach (Schema::getColumns(Beam::table('hooks')) as $column) {
            $types[(string) $column['name']] = strtolower((string) ($column['type_name'] ?? '?'));
        }

        return $types;
    }

    /** @return list<string> */
    protected function indexNames(): array
    {
        $names = array_map('strval', array_column(Schema::getIndexes(Beam::table('hooks')), 'name'));
        sort($names);

        return $names;
    }

    /** The integer type names the two drivers under test report for an `unsignedBigInteger`. */
    protected function isInteger(string $typeName): bool
    {
        return in_array($typeName, ['int8', 'bigint', 'integer', 'int', 'int4'], true);
    }

    // ── The premise ─────────────────────────────────────────────────────────────────────────────

    /**
     * The legacy fixture is only worth anything if it is what the framework really emitted. This is
     * the probe that keeps `createLegacyTable()` honest: if a future Laravel changes what
     * `nullableMorphs()` compiles to, this fails here rather than quietly turning every assertion
     * below into a test of a shape no host ever had.
     */
    public function test_the_legacy_shape_is_what_nullable_morphs_still_emits(): void
    {
        $this->createLegacyTable();

        $types = $this->types();

        $this->assertTrue(
            $this->isInteger($types['owner_id']),
            "`nullableMorphs('owner')` no longer emits an integer id (`{$types['owner_id']}`) — this "
            .'fixture, and the ALTER it exercises, are about a shape that no longer exists.',
        );
        $this->assertTrue($this->isInteger($types['subject_id']));
    }

    // ── The conversion ──────────────────────────────────────────────────────────────────────────

    public function test_it_widens_both_morph_ids_on_an_already_migrated_host(): void
    {
        $this->createLegacyTable();

        $this->widening()->up();

        foreach (['owner_id', 'subject_id'] as $column) {
            $this->assertFalse(
                $this->isInteger($this->types()[$column]),
                "`{$column}` is still an integer column after the widening ALTER.",
            );
        }
    }

    public function test_it_keeps_the_rows_a_host_already_has(): void
    {
        $this->createLegacyTable();

        $id = (string) Str::uuid();

        DB::table(Beam::table('hooks'))->insert([
            'id' => $id,
            'endpoint' => 'https://receiver.test/legacy',
            'secret' => str_repeat('a', 64),
            'events' => json_encode(['tenants.provisioned']),
            'owner_type' => 'user',
            'owner_id' => 7,
            'subject_type' => 'composition',
            'subject_id' => 42,
            'consecutive_failures' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->widening()->up();

        $row = DB::table(Beam::table('hooks'))->where('id', $id)->first();

        // Compared as strings on purpose: the value survived, and it survived INTO the string column
        // — a bigint that came back as an int would mean the ALTER did not run.
        $this->assertSame('7', (string) $row->owner_id);
        $this->assertSame('42', (string) $row->subject_id);
        $this->assertSame('user', $row->owner_type);
    }

    /**
     * The index is the thing a "just recreate the column" repair loses. Both morph pairs are indexed
     * — by `nullableMorphs()` before the widening and by the create stub's own explicit `index()`
     * after it — and the names are identical across the change by construction (the stub declares
     * them unnamed so Laravel generates the same string). A host that migrates THROUGH the ALTER
     * must end up with the same index set a fresh install gets.
     */
    public function test_it_keeps_the_morph_indexes_and_lands_on_the_fresh_install_index_set(): void
    {
        $this->createLegacyTable();
        $legacyIndexes = $this->indexNames();

        $this->widening()->up();
        $this->assertSame($legacyIndexes, $this->indexNames());

        // …and the same set a virgin host gets from the current create.
        Schema::dropIfExists(Beam::table('hooks'));
        $this->create()->up();

        $this->assertSame($legacyIndexes, $this->indexNames());
    }

    public function test_a_uuid_owner_persists_once_the_column_is_widened(): void
    {
        $this->createLegacyTable();
        $this->widening()->up();

        $owner = (string) Str::uuid();
        $id = (string) Str::uuid();

        DB::table(Beam::table('hooks'))->insert([
            'id' => $id,
            'endpoint' => 'https://receiver.test/uuid-owner',
            'secret' => str_repeat('b', 64),
            'events' => json_encode(['tenants.provisioned']),
            'owner_type' => 'user',
            'owner_id' => $owner,
            'consecutive_failures' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame($owner, DB::table(Beam::table('hooks'))->where('id', $id)->value('owner_id'));
    }

    /**
     * THE DEFECT ITSELF, and it only runs where it can be seen. sqlite's type affinity stores a uuid
     * in an `integer` column without complaint, so this skips there rather than asserting a pass it
     * has not earned — the Postgres subclass is where the 22P02 lives.
     */
    public function test_the_legacy_column_refuses_a_uuid_owner_and_the_widened_one_accepts_it(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped(
                'sqlite has type affinity, not type enforcement: a uuid lands in an integer column '
                .'without error, so the defect under repair is invisible on this driver. '
                .'BeamHookMorphWideningPostgresTest is the instrument for it.',
            );
        }

        $this->createLegacyTable();

        $owner = (string) Str::uuid();
        $insert = fn () => DB::table(Beam::table('hooks'))->insert([
            'id' => (string) Str::uuid(),
            'endpoint' => 'https://receiver.test/uuid-owner',
            'secret' => str_repeat('c', 64),
            'events' => json_encode(['tenants.provisioned']),
            'owner_type' => 'user',
            'owner_id' => $owner,
            'consecutive_failures' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $insert();
            $this->fail('The legacy bigint column accepted a uuid owner — there is nothing to repair.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('22P02', $e->getMessage());
        }

        $this->widening()->up();

        $insert();

        $this->assertSame(1, DB::table(Beam::table('hooks'))->where('owner_id', $owner)->count());
    }
}
