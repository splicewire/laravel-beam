<?php

namespace Splicewire\Beam\Tests\Storage;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Schema\BeamSchemaRegistry;
use Splicewire\Beam\Storage\ConnectionAwareStorageDriver;
use Splicewire\Beam\Storage\DeletableStorageDriver;
use Splicewire\Beam\Storage\DiskStorageDriver;
use Splicewire\Beam\Storage\ParticleStorageDriver;
use Splicewire\Beam\Storage\StackedStorageDriver;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The **domain-neutral storage seam** (beam-storage-driver charter) — the free-beam-core
 * {@see StorageDriver} port generalized FROM {@see BeamSchemaRegistry}. Every
 * driver (Particle / Disk / Stacked) is exercised against the SAME port contract
 * (`read·write·list·staleness`), then the default `Stacked(Particle-primary, Disk-mirror)` is asserted
 * for its shadow-on-read + materialize-on-save behavior (the schema-registry ordering, lifted).
 */
class StorageDriverTest extends TestCase
{
    private const SECOND = 'second';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::SECOND => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        $this->createTables();
        Schema::connection(self::SECOND)->create(Beam::table('particles'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->string('source_tier')->default('local')->index();
            $table->timestamps();
        });

        Gate::define('create', fn ($user = null) => true);
        Gate::define('update', fn ($user = null) => true);

        Storage::fake('local');
    }

    private function particleDriver(): ParticleStorageDriver
    {
        return new ParticleStorageDriver($this->app->make(ParticleWriter::class));
    }

    private function diskDriver(): DiskStorageDriver
    {
        return new DiskStorageDriver(Storage::disk('local'));
    }

    /** Every driver honors the same port contract: write → read round-trips, list filters by namespace. */
    public function test_each_driver_honors_the_port_contract(): void
    {
        foreach ($this->driversUnderTest() as $label => $driver) {
            $body = ['heading' => 'Hi '.$label, 'blocks' => []];

            // Namespaces are label-scoped: the Particle/Stacked drivers share the ONE beam_particles
            // store, so a per-label namespace keeps each driver's list() assertions independent.
            $primaryNs = "kit.hero.{$label}";
            $otherNs = "site.pages.{$label}";

            $written = $driver->write($this->keyFor($driver, 'a'), $body, $primaryNs);
            $this->assertInstanceOf(StorageItem::class, $written, $label);
            $this->assertSame($primaryNs, $written->namespace, $label);

            $read = $driver->read($written->key);
            $this->assertNotNull($read, $label);
            $this->assertSame($body, $read->body, $label);
            $this->assertSame($primaryNs, $read->namespace, $label);

            // A second item under a DIFFERENT namespace — list($namespace) filters.
            $driver->write($this->keyFor($driver, 'b'), ['x' => 1], $otherNs);
            $this->assertCount(1, $driver->list($primaryNs), $label.' list(namespace) filters');
            $this->assertGreaterThanOrEqual(2, count($driver->list()), $label.' list() returns all');

            // A miss reads null.
            $this->assertNull($driver->read('does-not-exist-'.$label), $label);
        }
    }

    /** The default Stacked: DB is source-of-record, disk a materialized projection. */
    public function test_stacked_shadows_on_read_and_materializes_on_save(): void
    {
        $particle = $this->particleDriver();
        $disk = $this->diskDriver();
        $stacked = new StackedStorageDriver($particle, $disk);

        $body = ['heading' => 'Stacked'];
        $written = $stacked->write('', $body, 'kit.hero');

        // Materialize-on-save: the SAME key now resolves on the mirror disk too.
        $this->assertNotNull($disk->read($written->key), 'the disk mirror was materialized on save');
        $this->assertSame($body, $disk->read($written->key)->body);

        // Read is primary-first (the particle source-of-record shadows the mirror).
        $this->assertSame($body, $stacked->read($written->key)->body);

        // list() is the primary's view.
        $this->assertCount(1, $stacked->list('kit.hero'));
    }

    /** staleness compares a candidate mtime against the stored item (the update-from-newer seam). */
    public function test_staleness_compares_candidate_against_stored(): void
    {
        $disk = $this->diskDriver();
        $item = $disk->write('kit/hero/component/hero.tsx', ['a' => 1], 'kit.hero');

        $stored = $item->modifiedAt ?? time();
        $this->assertSame(1, $disk->staleness($item->key, $stored + 100), 'a newer candidate is stale-positive');
        $this->assertSame(-1, $disk->staleness($item->key, $stored - 100), 'an older candidate is stale-negative');
        $this->assertSame(0, $disk->staleness('absent', 12345), 'an absent key is neutral');
    }

    /** Each shipped driver can remove what it stored; deleting a missing key is a no-op, not an error. */
    public function test_each_driver_deletes_an_item_and_ignores_a_missing_key(): void
    {
        foreach ($this->driversUnderTest() as $label => $driver) {
            $this->assertInstanceOf(DeletableStorageDriver::class, $driver, $label);

            $written = $driver->write($this->keyFor($driver, 'gone'), ['a' => 1], "kit.delete.{$label}");
            $this->assertNotNull($driver->read($written->key), $label);

            $driver->delete($written->key);

            $this->assertNull($driver->read($written->key), $label.' reads null after delete');
            $driver->delete($written->key);
            $driver->delete('does-not-exist-'.$label);
        }
    }

    /**
     * The default Stacked removes the source-of-record row AND the materialized disk mirror — the pair a
     * write created. Measured live (ux-demo-convergence step 2): a force-deleted entry left exactly this
     * pair behind, one `beam_particles` row and one mirror file per run.
     */
    public function test_stacked_delete_removes_the_row_and_the_mirror_file(): void
    {
        $disk = $this->diskDriver();
        $stacked = new StackedStorageDriver($this->particleDriver(), $disk);

        $written = $stacked->write('', [], '');
        $this->assertTrue(Storage::disk('local')->exists($written->key), 'precondition: the mirror file exists');
        $this->assertSame(1, BeamParticle::query()->whereKey($written->key)->count(), 'precondition: the row exists');

        $stacked->delete($written->key);

        $this->assertSame(0, BeamParticle::query()->whereKey($written->key)->count(), 'the particle row is gone');
        $this->assertFalse(Storage::disk('local')->exists($written->key), 'the mirror file is gone');
    }

    /**
     * A stack holding a tier that cannot delete refuses the delete and removes NOTHING: a delete that
     * returns means every tier the stack wrote is gone, so a caller can never read success as full
     * removal while a copy survives in a plain tier (build-qa 947dd76f MED).
     */
    public function test_stacked_delete_refuses_and_removes_nothing_when_a_tier_cannot_delete(): void
    {
        $disk = $this->diskDriver();
        $readOnly = new class implements StorageDriver
        {
            public function read(string $key): ?StorageItem
            {
                return null;
            }

            public function write(string $key, array $body, ?string $namespace = null): StorageItem
            {
                return new StorageItem($key, $body, $namespace, null);
            }

            public function list(?string $namespace = null): array
            {
                return [];
            }

            public function staleness(string $key, int $candidateModifiedAt): int
            {
                return 0;
            }
        };
        $stacked = new StackedStorageDriver($disk, $readOnly);

        $written = $stacked->write('kit/hero/component/skip.tsx', ['a' => 1], 'kit.skip');

        try {
            $stacked->delete($written->key);
            $this->fail('a stack with a non-deletable tier reported a delete');
        } catch (LogicException $refused) {
            $this->assertStringContainsString('cannot delete', $refused->getMessage());
        }

        $this->assertNotNull($disk->read($written->key), 'the deletable tier was emptied before the refusal');
        $this->assertFalse($stacked->canDelete());
        $this->assertTrue((new StackedStorageDriver($this->particleDriver(), $disk))->canDelete());
    }

    /**
     * A particle driver bound to a connection reads and deletes THERE, never on the default: the
     * connection travels with the driver instead of through the process-wide default (build-qa R2 HIGH).
     */
    public function test_a_particle_driver_bound_to_a_connection_deletes_there_and_not_on_the_default(): void
    {
        $id = '01a00000-0000-7000-8000-0000000000b1';
        $this->particleRow(self::SECOND, $id);
        $this->particleRow(null, $id);
        $default = DB::getDefaultConnection();

        $bound = $this->particleDriver()->onConnection(self::SECOND);
        $this->assertNotNull($bound->read($id));
        $bound->delete($id);

        $this->assertSame(0, $this->particleCount(self::SECOND, $id), 'the bound connection kept its row');
        $this->assertSame(1, $this->particleCount(null, $id), 'the default connection lost a same-id row');
        $this->assertSame($default, DB::getDefaultConnection());
    }

    /** The interleaving control: while a bound delete runs, the process default is never the bound one. */
    public function test_a_bound_delete_never_changes_the_default_connection_while_it_runs(): void
    {
        $id = '01a00000-0000-7000-8000-0000000000b2';
        $this->particleRow(self::SECOND, $id);
        $default = DB::getDefaultConnection();
        $seen = [];
        BeamParticle::deleting(function () use (&$seen): void {
            $seen[] = DB::getDefaultConnection();
        });

        $this->particleDriver()->onConnection(self::SECOND)->delete($id);

        $this->assertSame([$default], $seen);
    }

    public function test_a_stacked_driver_binds_its_connection_aware_tiers(): void
    {
        $disk = $this->diskDriver();
        $stacked = (new StackedStorageDriver($this->particleDriver(), $disk))->onConnection(self::SECOND);
        $written = $stacked->write('', ['a' => 1], 'kit.bound');
        $this->particleRow(null, $written->key);

        $stacked->delete($written->key);

        $this->assertSame(0, $this->particleCount(self::SECOND, $written->key));
        $this->assertSame(1, $this->particleCount(null, $written->key), 'a same-id default row was deleted');
        $this->assertNull($disk->read($written->key));
    }

    /** A disk that refuses to delete fails loudly; a returning delete always means removal (R2 MED). */
    public function test_a_disk_that_refuses_the_delete_throws(): void
    {
        $refusing = $this->refusingDiskDriver();
        $refusing->write('kit/hero/component/stuck.tsx', ['a' => 1], 'kit.stuck');

        $this->expectException(RuntimeException::class);
        $refusing->delete('kit/hero/component/stuck.tsx');
    }

    public function test_a_cancelled_particle_delete_throws_and_keeps_the_row(): void
    {
        $id = '01a00000-0000-7000-8000-0000000000b3';
        $this->particleRow(null, $id);
        BeamParticle::deleting(fn (): bool => false);

        // Captured, not asserted inside the try: PHPUnit's own AssertionFailedError is a RuntimeException.
        $thrown = null;
        try {
            $this->particleDriver()->delete($id);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown, 'a cancelled particle delete returned as removed');

        $this->assertSame(1, $this->particleCount(null, $id));
    }

    /** Mirrors go first, so a mirror that refuses stops the stack before the source-of-record row. */
    public function test_a_stack_whose_mirror_refuses_keeps_the_particle_row(): void
    {
        $stacked = new StackedStorageDriver($this->particleDriver(), $this->refusingDiskDriver());
        $written = $stacked->write('', ['a' => 1], 'kit.stuck');

        $thrown = null;
        try {
            $stacked->delete($written->key);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $thrown, 'a stack whose mirror refused reported a delete');

        $this->assertSame(1, $this->particleCount(null, $written->key), 'the source-of-record row went first');
    }

    /**
     * A BOUND stack deletes only when every tier is connection-aware: a tier that is not might be
     * DB-backed and would still delete on the default connection while the bound tiers delete on theirs
     * (build-qa R3 HIGH). It refuses instead — canDelete() false, delete() throws and removes nothing. The
     * disk tier says outright that it is connection-independent, so the default stack still deletes.
     */
    public function test_a_bound_stack_with_a_tier_that_is_not_connection_aware_refuses_to_delete(): void
    {
        $disk = $this->diskDriver();
        $this->assertInstanceOf(ConnectionAwareStorageDriver::class, $disk);
        $this->assertSame($disk, $disk->onConnection(self::SECOND), 'the disk tier is connection-independent');
        $this->assertTrue((new StackedStorageDriver($this->particleDriver(), $disk))->onConnection(self::SECOND)->canDelete());

        $mixed = new StackedStorageDriver($this->particleDriver(), $this->unbindableDeletableDriver());
        $this->assertTrue($mixed->canDelete(), 'unbound, every tier works on the default connection');

        $bound = $mixed->onConnection(self::SECOND);
        $this->assertFalse($bound->canDelete());

        $id = '01a00000-0000-7000-8000-0000000000b4';
        $this->particleRow(self::SECOND, $id);
        $this->expectException(LogicException::class);
        try {
            $bound->delete($id);
        } finally {
            $this->assertSame(1, $this->particleCount(self::SECOND, $id), 'the bound particle tier deleted before the refusal');
        }
    }

    public function test_stacked_rejects_empty_tiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StackedStorageDriver;
    }

    /** @return array<string, StorageDriver> */
    private function driversUnderTest(): array
    {
        return [
            'particle' => $this->particleDriver(),
            'disk' => $this->diskDriver(),
            'stacked' => new StackedStorageDriver($this->particleDriver(), $this->diskDriver()),
        ];
    }

    /**
     * The Particle driver mints its own key (empty key ⇒ new particle id); the Disk/Stacked drivers take
     * a caller path. Returning '' for particle lets each write mint a fresh source-of-record row.
     */
    private function keyFor(StorageDriver $driver, string $suffix): string
    {
        return $driver instanceof ParticleStorageDriver ? '' : "kit/hero/component/hero-{$suffix}.tsx";
    }

    /** A deletable tier that does not say which connection it uses — as a host's DB-backed tier might not. */
    private function unbindableDeletableDriver(): DeletableStorageDriver
    {
        return new class implements DeletableStorageDriver
        {
            public function read(string $key): ?StorageItem
            {
                return null;
            }

            public function write(string $key, array $body, ?string $namespace = null): StorageItem
            {
                return new StorageItem($key, $body, $namespace, null);
            }

            public function list(?string $namespace = null): array
            {
                return [];
            }

            public function staleness(string $key, int $candidateModifiedAt): int
            {
                return 0;
            }

            public function canDelete(): bool
            {
                return true;
            }

            public function delete(string $key): void {}
        };
    }

    private function refusingDiskDriver(): DiskStorageDriver
    {
        $fake = Storage::disk('local');

        return new DiskStorageDriver(new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function delete($paths)
            {
                return false;
            }
        });
    }

    private function particleRow(?string $connection, string $id): void
    {
        DB::connection($connection)->table(Beam::table('particles'))->insert([
            'id' => $id, 'payload' => '[]', 'meta' => '{"namespace":""}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function particleCount(?string $connection, string $id): int
    {
        return DB::connection($connection)->table(Beam::table('particles'))->where('id', $id)->count();
    }

    private function createTables(): void
    {
        Schema::create(Beam::table('particles'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->string('source_tier')->default('local')->index();
            $table->timestamps();
        });

        Schema::create(Beam::table('versions'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('versionable_type');
            $table->uuid('versionable_id');
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->string('label')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->unique(['versionable_type', 'versionable_id', 'version']);
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableUuidMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableUuidMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }
}
