<?php

namespace Splicewire\Beam\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Schema\BeamSchemaRegistry;
use Splicewire\Beam\Schema\DatabaseSchemaRegistry;
use Splicewire\Beam\Schema\SchemaSources;
use Splicewire\Beam\Tests\TestCase;

/**
 * The boot-time schema-SOURCE factory registry (JN-15 / ADR-0192 §5) and the `fleet` tier's
 * load-bearing ordering (§4): a package's provider contributes a tier without the host being
 * edited, and — because reads are first-hit-wins — a tenant DB registration can never shadow a
 * fleet conformance artifact, while ordinary content schemas keep their db-over-file override.
 */
class SchemaSourcesTest extends TestCase
{
    private string $fleetDir;

    private string $frozenDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fleetDir = sys_get_temp_dir().'/fleet-'.uniqid();
        $this->frozenDir = sys_get_temp_dir().'/frozen-'.uniqid();
        @mkdir($this->fleetDir, 0775, true);
        @mkdir($this->frozenDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->fleetDir, $this->frozenDir] as $dir) {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }

        parent::tearDown();
    }

    private function createDbTier(): void
    {
        Schema::create(Beam::table('schemas'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_id')->unique();
            $table->string('schema_name')->nullable()->index();
            $table->integer('version')->nullable();
            $table->string('fingerprint');
            $table->string('artifact');
            $table->timestamps();
        });
    }

    private function schema(string $id, string $marker): array
    {
        return ['$id' => $id, 'type' => 'object', 'x-marker' => $marker];
    }

    public function test_the_provider_binds_a_singleton_seeded_with_the_fleet_tier(): void
    {
        $sources = $this->app->make(SchemaSources::class);

        $this->assertTrue($sources->has('fleet'));
        $this->assertSame($sources, $this->app->make(SchemaSources::class));
        $this->assertInstanceOf(FilesystemSchemaRegistry::class, ($sources->factories()['fleet'])());
    }

    public function test_a_contributed_key_missing_from_config_is_appended_at_lowest_precedence(): void
    {
        $sources = (new SchemaSources)
            ->register('fleet', fn () => new FilesystemSchemaRegistry($this->fleetDir))
            ->register('theme', fn () => new FilesystemSchemaRegistry($this->frozenDir));

        // Configured order is authoritative; unlisted contributions append in registration order.
        $this->assertSame(['fleet', 'db', 'file', 'theme'], $sources->orderedSources(['fleet', 'db', 'file']));

        // A configured position wins over the registration-order append.
        $this->assertSame(['theme', 'fleet'], $sources->orderedSources(['theme']));
    }

    public function test_a_tenant_db_registration_cannot_shadow_a_fleet_artifact(): void
    {
        $this->createDbTier();

        $vocabId = 'https://beam.test/schemas/splice/vocabulary/1';

        // The committed fleet conformance artifact…
        (new FilesystemSchemaRegistry($this->fleetDir))->register($this->schema($vocabId, 'from-fleet'));

        // …and a tenant's attempt to override the same $id through the DB tier.
        (new DatabaseSchemaRegistry)->register($this->schema($vocabId, 'from-tenant-db'));

        $registry = new BeamSchemaRegistry(
            ['fleet', 'db', 'file'],
            [
                'fleet' => fn () => new FilesystemSchemaRegistry($this->fleetDir),
                'db' => fn () => new DatabaseSchemaRegistry,
                'file' => fn () => new FilesystemSchemaRegistry($this->frozenDir),
            ],
        );

        $this->assertSame(
            'from-fleet',
            $registry->get($vocabId)['x-marker'],
            'The fleet tier (ahead of db) must not be shadowable by a tenant registration.',
        );
    }

    /**
     * beam-facade ticket 150. The two orderings are separate: `sources` is READ precedence, and the
     * write tier is declared. Before 150 `register()` targeted `$sources[0]`, so this exact
     * configuration — the estate's real one — sent every tenant registration into the shared,
     * git-tracked, publicly-served fleet directory.
     */
    public function test_a_write_lands_in_the_declared_write_source_not_the_first_read_source(): void
    {
        $this->createDbTier();

        $id = 'https://beam.test/schemas/content/written/1';

        $registry = new BeamSchemaRegistry(
            ['fleet', 'db', 'file'],
            [
                'fleet' => fn () => new FilesystemSchemaRegistry($this->fleetDir),
                'db' => fn () => new DatabaseSchemaRegistry,
                'file' => fn () => new FilesystemSchemaRegistry($this->frozenDir),
            ],
            'db',
        );

        $registry->register($this->schema($id, 'written'));

        $this->assertTrue(
            (new DatabaseSchemaRegistry)->has($id),
            'The declared write source (db) must receive the registration.',
        );
        $this->assertSame(
            [],
            glob($this->fleetDir.'/*') ?: [],
            'sources[0] (fleet) must NOT receive it — that was the tenancy defect 150 closed.',
        );
    }

    /**
     * The fallback that keeps a single-tier host working: a `['file']`-only host inherits the package
     * default write source of `db`, does not configure a `db` tier, and must keep writing to the one
     * tier it has rather than throwing. Whether `db` exists is a fact about the HOST, so this is
     * advisory-by-fallback, not fatal — the estate's rule for host-dependent checks.
     */
    public function test_an_unconfigured_write_source_falls_back_to_the_first_source(): void
    {
        $id = 'https://beam.test/schemas/content/fallback/1';

        $registry = new BeamSchemaRegistry(
            ['file'],
            ['file' => fn () => new FilesystemSchemaRegistry($this->frozenDir)],
            'db',
        );

        $this->assertSame('file', $registry->writeSource());

        $registry->register($this->schema($id, 'fallback'));

        $this->assertNotEmpty(
            glob($this->frozenDir.'/*') ?: [],
            'A file-only host must still write to its filesystem tier.',
        );
    }

    public function test_an_ordinary_content_schema_still_gets_tenant_db_override(): void
    {
        $this->createDbTier();

        $contentId = 'https://beam.test/schemas/content/article/1';

        // Not a fleet artifact — committed in the ordinary file tier, overridden by the tenant.
        (new FilesystemSchemaRegistry($this->frozenDir))->register($this->schema($contentId, 'from-file'));
        (new DatabaseSchemaRegistry)->register($this->schema($contentId, 'from-tenant-db'));

        $registry = new BeamSchemaRegistry(
            ['fleet', 'db', 'file'],
            [
                'fleet' => fn () => new FilesystemSchemaRegistry($this->fleetDir),
                'db' => fn () => new DatabaseSchemaRegistry,
                'file' => fn () => new FilesystemSchemaRegistry($this->frozenDir),
            ],
        );

        $this->assertSame(
            'from-tenant-db',
            $registry->get($contentId)['x-marker'],
            'Tenant override (db over file) must survive for ordinary content schemas.',
        );
    }
}
