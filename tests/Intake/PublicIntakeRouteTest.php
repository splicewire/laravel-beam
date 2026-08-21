<?php

namespace Splicewire\Beam\Tests\Intake;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Schemastud\DataSchemas\Lifecycle\FilesystemSchemaRegistry;
use Schemastud\JsonNs\Vocab\VocabularyRegistry;
use Schemastud\JsonNs\Vocab\VocabularyValidator;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Models\BeamSubmission;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureCheapV1;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureCheapV2;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureExpensiveV1;
use Splicewire\Beam\Tests\Schema\Fixtures\FixtureExpensiveV2;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The public intake door (beam-write-pipeline ticket 04) at the HTTP seam — the generalized-down
 * successor to submissions' `POST /schema-forms/{form}` tests, repointed onto `POST /beam/intake/{form}`.
 *
 * `contact` is a PUBLIC form (on the allow-list); `private` resolves to a real schema but is NOT on the
 * allow-list, so the deny-default gate refuses it. Everything rides {@see ParticleWriter}
 * and persists a {@see BeamSubmission} carrying intake-provenance facets (beam-facade ticket 51 — the
 * door used to write the populator-agnostic {@see BeamParticle}, so these assertions moved tables).
 *
 * `relative` is the class of schema this suite never exercised: an artifact whose `$id` is RELATIVE.
 * The formatted door stripped it before calling opis and the boolean acceptance gate did not, so a
 * conforming payload 422'd nowhere and 500'd at the write stage instead. Both doors now share the
 * strip, and the case is covered here because it is only reachable end-to-end.
 */
class PublicIntakeRouteTest extends TestCase
{
    private const CHEAP_STEM = 'https://schemas.splicewire.app/test/record-versioning-cheap';

    private const EXPENSIVE_STEM = 'https://schemas.splicewire.app/test/record-versioning-expensive';

    private const NAMESPACED_STEM = 'https://schemas.splicewire.app/test/namespaced-intake';

    private const VOCAB_URI = 'https://schemas.splicewire.app/splice/intake-grounding-test';

    /** No scheme — the shape a bare form ref stems to, and the one opis refuses to parse. */
    private const RELATIVE_STEM = 'relative-intake';

    private string $frozenDir;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Mount the opt-in door (config is read at boot, so it must be set here, pre-boot).
        $app['config']->set('beam.core.intake.enabled', true);
        $app['config']->set('beam.core.intake.forms', [
            'contact' => self::CHEAP_STEM,      // public
            'private' => self::EXPENSIVE_STEM,  // resolvable but NOT public
            'namespaced' => self::NAMESPACED_STEM, // public, declares @namespace content (ticket 02)
            'relative' => self::RELATIVE_STEM, // public, and its artifact's `$id` is RELATIVE (ticket 51)
        ]);
        $app['config']->set('beam.core.intake.public_schemas', [
            self::CHEAP_STEM,
            self::NAMESPACED_STEM,
            self::RELATIVE_STEM,
        ]);
        $app['config']->set('beam.core.intake.honeypot', ['enabled' => true, 'field' => 'website']);
        $app['config']->set('beam.core.intake.throttle', '2,1');
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

        // A schema whose artifact DECLARES namespace content (beam-namespace-wiring ticket 02): the
        // `splice:grounding` subtree of a submission must additionally conform to its `$vocabulary`.
        $registry->register([
            '$id' => self::NAMESPACED_STEM.'/1',
            'type' => 'object',
            'required' => ['title'],
            'properties' => [
                'title' => ['type' => 'string'],
                'splice:grounding' => ['type' => 'object'],
            ],
            '@namespace' => ['splice' => self::VOCAB_URI],
        ]);

        // A registered artifact whose `$id` is relative (ticket 51). Both validation doors must treat it
        // identically; before the shared strip the formatted door passed it and the boolean gate threw.
        $registry->register([
            '$id' => self::RELATIVE_STEM.'/1',
            'type' => 'object',
            'required' => ['title'],
            'properties' => ['title' => ['type' => 'string']],
            'additionalProperties' => false,
        ]);

        $this->app->singleton(SchemaRegistry::class, fn () => $registry);

        // The vocabulary the namespaced subtree validates against, behind the json-ns engine.
        $this->app->instance(VocabularyValidator::class, new VocabularyValidator(
            VocabularyRegistry::make()->registerJson(self::VOCAB_URI, (string) json_encode([
                'type' => 'object',
                'required' => ['sources'],
                'properties' => ['sources' => ['type' => 'array', 'minItems' => 1]],
            ])),
        ));

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
        $this->assertDatabaseCount(Beam::table('submissions'), 1);
        // The bare particle store is the SIBLING table and stays untouched — no pivot, no parent row.
        $this->assertDatabaseCount(Beam::table('particles'), 0);

        $record = BeamSubmission::firstOrFail();
        // `form_key` is the route's slug, not the resolved stem it maps to.
        $this->assertSame('contact', $record->form_key);
        // Migrate-on-read wiring is live: the write stamped the current schema id + status.
        $this->assertSame(self::CHEAP_STEM.'/2', $record->schema_id);
        $this->assertSame('current', $record->migration_status);

        // The snapshot tier is deliberately NOT stamped: this record carries a `schema_ref`, so under
        // ticket 47's rule a `meta.schema` here would be a decoy the notify path can never reach.
        $this->assertArrayNotHasKey('schema', $record->meta ?? []);

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
        $this->assertDatabaseCount(Beam::table('submissions'), 0);
    }

    public function test_an_invalid_payload_is_rejected_with_422(): void
    {
        $response = $this->postJson('beam/intake/contact', ['body' => 'no title']);

        $response->assertStatus(422)->assertJsonStructure(['message', 'errors']);
        $this->assertDatabaseCount(Beam::table('submissions'), 0);
    }

    public function test_a_namespaced_submission_violating_its_vocabulary_is_rejected_with_422(): void
    {
        // Structurally valid (title present, splice:grounding an object) — but the namespaced
        // subtree violates its namespace's $vocabulary (`sources` missing): ticket-02 enforcement
        // at the intake door, surfaced through the SAME formatted 422 error body.
        $response = $this->postJson('beam/intake/namespaced', [
            'title' => 'Hello',
            'splice:grounding' => ['nope' => true],
        ]);

        $response->assertStatus(422)->assertJsonStructure(['message', 'errors']);
        $this->assertStringContainsString('sources', json_encode($response->json('errors')));
        $this->assertDatabaseCount(Beam::table('submissions'), 0);
    }

    public function test_a_namespaced_submission_conforming_to_its_vocabulary_persists(): void
    {
        $response = $this->postJson('beam/intake/namespaced', [
            'title' => 'Hello',
            'splice:grounding' => ['sources' => ['ctx://profile']],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount(Beam::table('submissions'), 1);
    }

    public function test_a_conforming_payload_against_a_relative_id_schema_persists(): void
    {
        // Gate parity (ticket 51). The formatted door already stripped the relative `$id`, so this
        // payload passed validation and then hit the acceptance gate inside ParticleWriter, where opis
        // threw on the same `$id` and the gate's fail-closed `catch` reported *does not conform* — a
        // PayloadRejected 500 on a correct payload. Both doors share the strip now.
        $response = $this->postJson('beam/intake/relative', ['title' => 'Hello']);

        $response->assertStatus(201)->assertJson(['schemaRef' => self::RELATIVE_STEM.'/1']);
        $this->assertDatabaseCount(Beam::table('submissions'), 1);
        $this->assertSame('relative', BeamSubmission::firstOrFail()->form_key);
    }

    public function test_a_non_conforming_payload_against_a_relative_id_schema_is_still_a_422(): void
    {
        // The strip must not turn the relative-`$id` case into a door that accepts anything: the
        // schema is still enforced, in place, and refusal is the formatted 422 — never the gate's 500.
        $response = $this->postJson('beam/intake/relative', ['title' => 42]);

        $response->assertStatus(422)->assertJsonStructure(['message', 'errors']);
        $this->assertDatabaseCount(Beam::table('submissions'), 0);
    }

    public function test_a_payload_breaking_two_constraints_reports_both_fields(): void
    {
        // maxErrors (ticket 51): opis defaults to 1, so this used to report ONE pointer and the next
        // attempt reported the other. The door's contract is a field-keyed error map.
        //
        // Both violations sit under the SAME keyword (`properties`) on purpose — that is the whole
        // reach of `setMaxErrors()`. Opis short-circuits at the first failing KEYWORD regardless, so a
        // payload that also omits a required field still reports only the `required` failure; the
        // field-keyed map is a promise about one keyword's fields, never about every rule at once.
        $response = $this->postJson('beam/intake/contact', [
            'title' => 42,
            'body' => 42,
            'summary' => null,
        ]);

        $response->assertStatus(422);
        $errors = json_encode($response->json('errors'));
        $this->assertStringContainsString('/title', $errors);
        $this->assertStringContainsString('/body', $errors);
    }

    public function test_a_schema_not_on_the_allow_list_is_refused_by_the_deny_default_gate(): void
    {
        // `private` resolves to a real schema but is absent from `public_schemas` ⇒ 403, and — deny-first —
        // it is refused before its payload is even validated.
        $response = $this->postJson('beam/intake/private', ['anything' => 'goes']);

        $response->assertStatus(403);
        $this->assertDatabaseCount(Beam::table('submissions'), 0);
    }

    public function test_an_unknown_form_is_a_404(): void
    {
        $this->postJson('beam/intake/does-not-exist', ['x' => 1])->assertStatus(404);
        $this->assertDatabaseCount(Beam::table('submissions'), 0);
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
        // The door's own store (ticket 51) — mirrors `create_beam_submissions_table.php.stub`: NOT NULL
        // `form_key` and `payload`, no `head_version` (a submission is migrate-on-read, never versioned).
        Schema::create(Beam::table('submissions'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('form_key')->index();
            $table->string('schema_ref')->nullable();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->json('payload');
            $table->json('context')->nullable();
            $table->json('meta')->nullable();
            $table->uuid('user_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create(Beam::table('particles'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('schema_ref')->nullable()->index();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->string('head_version')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
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
