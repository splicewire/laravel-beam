<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Intake;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Splicewire\Beam\Models\SchemaRecord;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureCheapV1;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureCheapV2;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureExpensiveV1;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureExpensiveV2;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\RecordWriter;

/**
 * The public intake door (beam-write-pipeline ticket 04) at the HTTP seam — the generalized-down
 * successor to submissions' `POST /schema-forms/{form}` tests, repointed onto `POST /beam/intake/{form}`.
 *
 * `contact` is a PUBLIC form (on the allow-list); `private` resolves to a real schema but is NOT on the
 * allow-list, so the deny-default gate refuses it. Everything rides {@see RecordWriter}
 * and persists a base {@see SchemaRecord} carrying intake-provenance facets — no `FormSubmission` model.
 */
class PublicIntakeRouteTest extends TestCase
{
    private const CHEAP_STEM = 'https://schemas.splicewire.app/test/record-versioning-cheap';

    private const EXPENSIVE_STEM = 'https://schemas.splicewire.app/test/record-versioning-expensive';

    private string $frozenDir;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Mount the opt-in door (config is read at boot, so it must be set here, pre-boot).
        $app['config']->set('beam.intake.enabled', true);
        $app['config']->set('beam.intake.forms', [
            'contact' => self::CHEAP_STEM,      // public
            'private' => self::EXPENSIVE_STEM,  // resolvable but NOT public
        ]);
        $app['config']->set('beam.intake.public_schemas', [self::CHEAP_STEM]);
        $app['config']->set('beam.intake.honeypot', ['enabled' => true, 'field' => 'website']);
        $app['config']->set('beam.intake.throttle', '2,1');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->frozenDir = sys_get_temp_dir().'/intake-'.uniqid();
        @mkdir($this->frozenDir, 0775, true);

        $generator = new JsonSchemaGenerator(config('data-schemas', []));
        $registry = new FilesystemSchemaRegistry($this->frozenDir);
        foreach ([FixtureCheapV1::class, FixtureCheapV2::class, FixtureExpensiveV1::class, FixtureExpensiveV2::class] as $cls) {
            $registry->register($generator->generate(new ReflectionClass($cls)));
        }
        $this->app->singleton(SchemaRegistry::class, fn () => $registry);

        $this->createTables();
    }

    protected function tearDown(): void
    {
        if (isset($this->frozenDir) && is_dir($this->frozenDir)) {
            array_map('unlink', glob($this->frozenDir.'/*') ?: []);
            @rmdir($this->frozenDir);
        }

        parent::tearDown();
    }

    public function test_a_valid_submission_persists_a_schema_record_with_provenance_facets(): void
    {
        $response = $this->postJson('beam/intake/contact', [
            'title' => 'Hi there',
            'body' => 'A message',
            'summary' => null,
        ]);

        $response->assertStatus(201)->assertJson(['schemaRef' => self::CHEAP_STEM.'/2']);
        $this->assertDatabaseCount('schema_records', 1);

        $record = SchemaRecord::firstOrFail();
        // Migrate-on-read wiring is live: the write stamped the current schema id + status.
        $this->assertSame(self::CHEAP_STEM.'/2', $record->schema_id);
        $this->assertSame('current', $record->migration_status);

        // Intake-provenance facets landed on the record's meta.
        $intake = $record->meta['intake'] ?? [];
        $this->assertSame('public-intake', $intake['channel'] ?? null);
        $this->assertArrayHasKey('submitted_at', $intake);
        $this->assertArrayHasKey('context', $intake);
    }

    public function test_a_honeypot_hit_silently_succeeds_and_persists_nothing(): void
    {
        $response = $this->postJson('beam/intake/contact', [
            'title' => 'Bot',
            'body' => 'spam',
            'summary' => null,
            'website' => 'http://bot.example',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('schema_records', 0);
    }

    public function test_an_invalid_payload_is_rejected_with_422(): void
    {
        $response = $this->postJson('beam/intake/contact', ['body' => 'no title']);

        $response->assertStatus(422)->assertJsonStructure(['message', 'errors']);
        $this->assertDatabaseCount('schema_records', 0);
    }

    public function test_a_schema_not_on_the_allow_list_is_refused_by_the_deny_default_gate(): void
    {
        // `private` resolves to a real schema but is absent from `public_schemas` ⇒ 403, and — deny-first —
        // it is refused before its payload is even validated.
        $response = $this->postJson('beam/intake/private', ['anything' => 'goes']);

        $response->assertStatus(403);
        $this->assertDatabaseCount('schema_records', 0);
    }

    public function test_an_unknown_form_is_a_404(): void
    {
        $this->postJson('beam/intake/does-not-exist', ['x' => 1])->assertStatus(404);
        $this->assertDatabaseCount('schema_records', 0);
    }

    public function test_the_route_is_throttled(): void
    {
        // Throttle is 2/min; the third request within the window is rejected before the controller runs.
        $this->postJson('beam/intake/contact', ['title' => 'a', 'body' => null, 'summary' => null])->assertStatus(201);
        $this->postJson('beam/intake/contact', ['title' => 'b', 'body' => null, 'summary' => null])->assertStatus(201);
        $this->postJson('beam/intake/contact', ['title' => 'c', 'body' => null, 'summary' => null])->assertStatus(429);
    }

    private function createTables(): void
    {
        Schema::create('schema_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('versions', function (Blueprint $table) {
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
