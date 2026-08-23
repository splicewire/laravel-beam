<?php

namespace Splicewire\Beam\Tests\Write;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Concerns\Deduplicates;
use Splicewire\Beam\Concerns\PersistsBeamParticle;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\BeamSubmission;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\Keywords;
use Splicewire\Beam\Submissions\RecordsSubmissions;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\Dedupe\DedupeNotSupported;
use Splicewire\Beam\Write\Dedupe\DuplicateRejected;
use Splicewire\Beam\Write\ParticleWriter;
use Splicewire\Beam\Write\PermissiveAcceptanceGate;
use Splicewire\Beam\Write\PermissiveWriteGate;
use Splicewire\Beam\Write\Stages\DedupeStage;

/**
 * `x-beam-dedupe` at the write seam (beam-facade ticket 66; design in ticket 50). Every assertion
 * drives the ONE {@see ParticleWriter} entry point and observes only what a caller observes — rows
 * landed or not, the model handed back, the event emitted — never the stage's internals.
 *
 * The chain under test is the SHIPPED DEFAULT one, built exactly as
 * {@see RecordsSubmissions} builds it (permissive gates, 4-arg
 * constructor), because that is the path both hosts that want dedupe actually call — a host-passed
 * stage would never fire for either of them (ticket 50 §7).
 *
 * The target resolver is a stub rather than the filesystem-registry fixtures the rest of this
 * directory uses, ON PURPOSE: those fixtures opt into versioned identity and every test built on
 * them is currently dark on `MissingSchemaBaseUri` (beam-facade ticket 85). A keyword test whose
 * subject is the keyword should not inherit another ticket's red.
 */
class DedupeStageTest extends TestCase
{
    /** A `waitlist/1` binding stems to the `waitlist` record type the stub resolver answers for. */
    private const REF = 'waitlist/1';

    private const STEM = 'waitlist';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create(Beam::table('submissions'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('capture_key')->index();
            $table->string('schema_ref')->nullable();
            $table->string('schema_id')->nullable()->index();
            $table->string('migration_status')->nullable()->index();
            $table->json('payload');
            $table->json('context')->nullable();
            $table->json('meta')->nullable();
            $table->string('dedupe_key')->nullable()->index();
            $table->uuid('user_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('opted_out_captures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('capture_key')->nullable();
            $table->string('schema_ref')->nullable();
            $table->json('payload')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    // A schema with no keyword at all is every schema in the estate today: nothing is stamped and
    // nothing changes. This is the regression guard on adding a stage to the shipped chain.
    public function test_a_schema_with_no_keyword_leaves_the_capture_untouched(): void
    {
        $first = $this->write(['email' => 'ada@example.test'], schema: ['type' => 'object']);
        $second = $this->write(['email' => 'ada@example.test'], schema: ['type' => 'object']);

        $this->assertDatabaseCount(Beam::table('submissions'), 2);
        $this->assertNull($first->dedupe_key);
        $this->assertNull($second->dedupe_key);
    }

    /**
     * `admit` — the ledger stays append-only. The repeat LANDS, stamped with the same key and a
     * `meta.dedupe.first_seen_id` linkage back to the row it matched (ticket 50 §1, §10). No
     * ordinal: it is derivable and would be wrong under concurrency.
     */
    public function test_admit_lands_the_repeat_and_links_it_to_the_first_capture(): void
    {
        $first = $this->write(['email' => 'ada@example.test']);
        $second = $this->write(['email' => 'ada@example.test']);

        $this->assertDatabaseCount(Beam::table('submissions'), 2);
        $this->assertFalse($first->is($second));
        $this->assertNotNull($first->dedupe_key);
        $this->assertSame($first->dedupe_key, $second->dedupe_key);

        $this->assertNull($first->meta['dedupe'] ?? null, 'the first capture has nothing to link back to');
        $this->assertSame(['first_seen_id' => $first->getKey()], $second->meta['dedupe']);
        $this->assertArrayNotHasKey('ordinal', $second->meta['dedupe']);
    }

    /** Mode omitted means `admit` — a stated default, not a door that closes (ticket 50 §4). */
    public function test_an_omitted_mode_means_admit(): void
    {
        $schema = [Keywords::Dedupe => ['by' => ['email']]];

        $first = $this->write(['email' => 'ada@example.test'], schema: $schema);
        $second = $this->write(['email' => 'ada@example.test'], schema: $schema);

        $this->assertDatabaseCount(Beam::table('submissions'), 2);
        $this->assertSame(['first_seen_id' => $first->getKey()], $second->meta['dedupe']);
    }

    /** Under `admit` a repeat persists, so the signal fires — and a beam-notifications host mails. */
    public function test_admit_emits_the_persist_signal_for_the_repeat(): void
    {
        Event::fake([BeamParticlePersisted::class]);

        $this->write(['email' => 'ada@example.test']);
        $this->write(['email' => 'ada@example.test']);

        Event::assertDispatchedTimes(BeamParticlePersisted::class, 2);
    }

    /** `ignore` drops the repeat and hands back the row that matched. Nothing new persists. */
    public function test_ignore_returns_the_matched_row_and_persists_nothing(): void
    {
        $first = $this->write(['email' => 'ada@example.test'], mode: 'ignore');
        $second = $this->write(['email' => 'ada@example.test'], mode: 'ignore');

        $this->assertDatabaseCount(Beam::table('submissions'), 1);
        $this->assertTrue($second->is($first));
        $this->assertTrue($second->exists);
    }

    /**
     * THE SECURITY PROPERTY, not a nicety (ticket 50 §6). A repeat under `ignore` must be
     * byte-identical to a fresh capture in everything a caller can observe — otherwise a public door
     * becomes an email-existence oracle: an anonymous caller probes an address and learns from the
     * absent id, a different id shape, or a changed status whether it is already on the list.
     *
     * Asserted on the exact values the door puts in its 201 body.
     */
    public function test_an_ignored_repeat_is_indistinguishable_from_a_fresh_capture(): void
    {
        $fresh = $this->write(['email' => 'grace@example.test'], mode: 'ignore');
        $repeat = $this->write(['email' => 'grace@example.test'], mode: 'ignore');

        $body = static fn (Model $m) => ['id' => $m->getKey(), 'schemaRef' => $m->schema_ref];

        $this->assertSame($body($fresh), $body($repeat));
        $this->assertIsString($repeat->getKey());
        $this->assertNotEmpty($repeat->getKey(), 'an absent id is itself the disclosure');
        $this->assertTrue($repeat->exists, 'the returned row must be a real persisted row');
    }

    /** No persist under `ignore` means no event — and so NO notification. Easy to miss; asserted. */
    public function test_ignore_emits_no_persist_signal_for_the_repeat(): void
    {
        Event::fake([BeamParticlePersisted::class]);

        $this->write(['email' => 'ada@example.test'], mode: 'ignore');
        $this->write(['email' => 'ada@example.test'], mode: 'ignore');

        Event::assertDispatchedTimes(BeamParticlePersisted::class, 1);
    }

    /**
     * `reject` refuses the repeat with a 409-mapping exception and nothing persists. It is an
     * existence oracle by construction, which is why it is legitimate only behind a non-public door
     * — the mode's own documentation carries that ruling, not this test.
     */
    public function test_reject_refuses_the_repeat_and_nothing_persists(): void
    {
        Event::fake([BeamParticlePersisted::class]);

        $this->write(['email' => 'ada@example.test'], mode: 'reject');

        try {
            $this->write(['email' => 'ada@example.test'], mode: 'reject');
            $this->fail('expected a matching capture to be refused');
        } catch (DuplicateRejected $e) {
            $this->assertSame(409, $e->getStatusCode(), '409 with no HTTP wiring is the whole point');
        }

        $this->assertDatabaseCount(Beam::table('submissions'), 1);
        Event::assertDispatchedTimes(BeamParticlePersisted::class, 1);
    }

    /**
     * A missing declared field means NO key at all — never a key over the absence, which would
     * collide every payload missing the field with every other one, and under `reject` refuse all of
     * them (ticket 50 §8). Both captures land, both unstamped.
     */
    public function test_a_missing_declared_field_yields_no_key_and_dedupe_does_not_apply(): void
    {
        $first = $this->write(['name' => 'no email here'], mode: 'reject');
        $second = $this->write(['name' => 'also no email'], mode: 'reject');

        $this->assertDatabaseCount(Beam::table('submissions'), 2);
        $this->assertNull($first->dedupe_key);
        $this->assertNull($second->dedupe_key);
    }

    /** The same argument covers a present-but-empty value: no usable value, so no key. */
    public function test_an_empty_declared_field_yields_no_key(): void
    {
        $record = $this->write(['email' => '   '], mode: 'reject');

        $this->assertNull($record->dedupe_key);
    }

    /**
     * The keyword declared against a model that has not opted in THROWS — it is not a silent no-op.
     * Nine models compose {@see PersistsBeamParticle} and the stage is in the shipped default chain,
     * so a mis-declared keyword is reachable rather than theoretical, and a keyword that quietly
     * does nothing is worse than one that refuses (ticket 50 §11; ticket 40's failure mode).
     */
    public function test_a_model_without_the_marker_refuses_the_keyword(): void
    {
        $this->expectException(DedupeNotSupported::class);

        $this->writer(['type' => 'object', Keywords::Dedupe => ['by' => ['email']]])->write(
            new OptedOutCaptureFixture(['capture_key' => 'waitlist', 'schema_ref' => self::REF]),
            ['email' => 'ada@example.test'],
        );
    }

    /** Casefold + trim, generically — `Writer@x.com` and `writer@x.com` are ONE capture universe. */
    public function test_the_key_is_trimmed_and_casefolded(): void
    {
        $first = $this->write(['email' => 'Ada@Example.TEST']);
        $second = $this->write(['email' => '  ada@example.test  ']);

        $this->assertSame($first->dedupe_key, $second->dedupe_key);
    }

    /** A different capture kind is a different universe: the scope is folded INTO the hash. */
    public function test_the_capture_scope_is_inside_the_key(): void
    {
        $waitlist = $this->write(['email' => 'ada@example.test'], captureKey: 'waitlist');
        $contact = $this->write(['email' => 'ada@example.test'], captureKey: 'contact');

        $this->assertNotSame($waitlist->dedupe_key, $contact->dedupe_key);
        $this->assertDatabaseCount(Beam::table('submissions'), 2);
        $this->assertNull($contact->meta['dedupe'] ?? null);
    }

    /**
     * THE RECIPE IS WRITE-ONCE (ticket 50 §8). A literal expected digest for a fixed input, so an
     * edit to the normalization, the ordering, the separator or the scope fails HERE rather than
     * silently partitioning every host's existing rows from every row written after it. If this
     * assertion needs changing, the change is a backfill and not an edit.
     */
    public function test_the_key_recipe_is_pinned_to_a_literal_digest(): void
    {
        $record = $this->write(['email' => '  Ada@Example.TEST '], captureKey: 'waitlist');

        $this->assertSame(
            'd2f7c0d06678d74304f4b96b9e87f6b7ed29297236ae15ce9e953f3091f5513a',
            $record->dedupe_key,
        );
    }

    /**
     * Multi-field keys hash in the keyword's DECLARED order, never sorted — the author's order is
     * stable and visible, and sorting would hide a reordering that changes nothing (ticket 50 §8).
     */
    public function test_multi_field_keys_hash_in_the_declared_order(): void
    {
        $payload = ['email' => 'ada@example.test', 'company' => 'analytical'];

        $forward = $this->write($payload, by: ['email', 'company']);
        $reverse = $this->write($payload, by: ['company', 'email']);

        $this->assertNotSame($forward->dedupe_key, $reverse->dedupe_key);
    }

    /** Write one capture through the shipped default chain under a stub-resolved schema. */
    private function write(
        array $payload,
        string $mode = 'admit',
        array $by = ['email'],
        string $captureKey = 'waitlist',
        ?array $schema = null,
    ): BeamSubmission {
        $schema ??= ['type' => 'object', Keywords::Dedupe => ['by' => $by, 'mode' => $mode]];

        /** @var BeamSubmission $written */
        $written = $this->writer($schema)->write(
            new BeamSubmission(['capture_key' => $captureKey, 'schema_ref' => self::REF]),
            $payload,
        );

        return $written;
    }

    /**
     * The 4-arg writer {@see RecordsSubmissions} builds — the shipped
     * default chain, so {@see DedupeStage} is present because the package ships it there and not
     * because this test inserted it.
     */
    private function writer(array $schema): ParticleWriter
    {
        return new ParticleWriter(
            new PermissiveWriteGate,
            $this->targets($schema),
            new PermissiveAcceptanceGate,
            $this->app->make(Dispatcher::class),
        );
    }

    private function targets(array $schema): SchemaTargetResolver
    {
        return new class(self::STEM, $schema) implements SchemaTargetResolver
        {
            public function __construct(private string $stem, private array $schema) {}

            public function targetFor(string $recordType, ?int $version = null): array
            {
                return $recordType === $this->stem ? $this->schema : [];
            }
        };
    }
}

/**
 * A capture model that composes the particle skeleton but NOT
 * {@see Deduplicates} — the eight-of-nine case (`Thread`, `Message`,
 * `BeamUxEntry`, `Clip`, …) whose table has no `dedupe_key` column.
 */
class OptedOutCaptureFixture extends Model
{
    use PersistsBeamParticle;

    protected $table = 'opted_out_captures';

    protected $fillable = ['capture_key', 'schema_ref', 'payload', 'meta'];
}
