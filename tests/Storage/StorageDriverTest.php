<?php

namespace Splicewire\Beam\Tests\Storage;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Schema\BeamSchemaRegistry;
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
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

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
