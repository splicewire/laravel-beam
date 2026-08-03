<?php

namespace Splicewire\Beam\Tests\Storage;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Splicewire\Beam\Beam;
use Splicewire\Beam\Schema\BeamSchemaRegistry;
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
