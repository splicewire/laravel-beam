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
