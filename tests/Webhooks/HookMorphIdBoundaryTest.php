<?php

namespace Splicewire\Beam\Tests\Webhooks;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Events\EventType;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Schema\BeamHookMorphWideningPostgresTest;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Webhooks\Http\HookSubscriptionController;

/**
 * The two morph ids on `beam_hooks` at the HTTP boundary — who may OWN a hook, and what happens to a
 * subject id the target model cannot use (ux-demo-convergence G3-FLAGSHIP-HOOKS-GUEST-LINKS).
 *
 * ## The defect this class is the regression test for
 *
 * Measured 2026-09-12 on the flagship's isolated fixture: subscribing a hook from the SPA answered
 * **500**, `SQLSTATE[22P02]: invalid input syntax for type bigint`, with the failing statement, the
 * bound uuid and the database name rendered verbatim in the client's toast. Two separate mistakes
 * met there and both are fixed, in different places, because either one alone still produces a 500:
 *
 *   1. The COLUMN could not hold the value the package's own writer put in it —
 *      `HookSubscriptionController::stampOwner()` writes `$request->user()->getKey()`, and a
 *      `nullableMorphs()` id is a bigint. Repaired in the schema (the create stub plus
 *      `widen_beam_hook_morph_ids_to_string` for already-migrated hosts).
 *   2. A caller-supplied `subject_id` reached `find()` unvetted, so an id the target model's key
 *      cannot parse became a driver error instead of the caller's own 422. Repaired in
 *      `HookSubscriptionReach::resolveSubject()`.
 *
 * ## Why the ownership assertions are about a UUID user specifically
 *
 * Not because uuid is the estate's key type — it is not, uniformly: the starters key `users` bigint
 * and the flagship keys tenant users uuid, which is exactly why the column is a `string` and not a
 * `uuid`. Both are asserted here, from the same door, because a repair that swapped one hard-coded
 * key type for another would pass a test that only knew about one of them.
 *
 * ## Why this cannot be asserted on the driver alone
 *
 * sqlite has type affinity, not type enforcement: the pre-repair bigint column accepted a uuid here
 * without complaint. So these tests would have passed against the defect, and they are not what
 * proves it gone — {@see BeamHookMorphWideningPostgresTest} is. What
 * these pin is the BOUNDARY behaviour, which is driver-independent: a string owner id round-trips as
 * the string it was written as, and a malformed subject id never reaches a query at all.
 */
class HookMorphIdBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $migration = require __DIR__.'/../../database/migrations/shared/create_beam_hooks_table.php.stub';
        $migration->up();

        Schema::create('int_keyed_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });

        Schema::create('uuid_keyed_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
        });

        Gate::before(fn () => true);

        $catalog = $this->app->make(EventTypeRegistry::class);
        $catalog->register(new EventType(name: 'ints.happened', subjectless: true, description: 'A probe fired.'));
        $catalog->register(new EventType(name: 'uuids.happened', subjectless: true, description: 'A probe fired.'));

        $registry = $this->app->make(ParticleResourceRegistry::class);
        $registry->registerClass(HookData::class);
        $registry->register(new ParticleResource(
            key: 'ints', backing: IntKeyedRecord::class, data: MorphProbeData::class, filterable: false,
        ));
        $registry->register(new ParticleResource(
            key: 'uuids', backing: UuidKeyedRecord::class, data: MorphProbeData::class, filterable: false,
        ));

        Route::post('hooks', [HookSubscriptionController::class, 'store'])->name('hooks.subscribe');
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            Schema::dropIfExists('int_keyed_records');
            Schema::dropIfExists('uuid_keyed_records');
        }

        parent::tearDown();
    }

    // ── Ownership: the principal's key type is the host's, and the column takes either ───────────

    public function test_a_uuid_keyed_principal_owns_the_hook_it_creates(): void
    {
        Bus::fake();

        $actor = new UuidKeyedUser;
        $this->actingAs($actor);

        $id = $this->postJson('/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['uuids.happened'],
        ])->assertCreated()->json('data.hook.id');

        $hook = Hook::findOrFail($id);

        $this->assertSame($actor->getKey(), $hook->owner_id);
        $this->assertSame($actor->getMorphClass(), $hook->owner_type);
    }

    public function test_a_bigint_keyed_principal_owns_the_hook_it_creates_as_a_string(): void
    {
        Bus::fake();

        $this->actingAs(new IntKeyedUser);

        $id = $this->postJson('/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['ints.happened'],
        ])->assertCreated()->json('data.hook.id');

        // `'1'`, not `1`: the column is a string, and the stamp casts so that what a host reads back
        // is what it wrote on every driver. An int here would mean the two disagree on sqlite and
        // agree on Postgres, which is the shape that hides in a test suite.
        $this->assertSame('1', Hook::findOrFail($id)->owner_id);
    }

    // ── Subjects: a malformed id is the caller's 422, never the driver's 500 ─────────────────────

    /**
     * The leak assertion is deliberately about the BODY rather than the status. A 500 whose body
     * happened to be empty would still be a defect, but the thing that reached a user on the
     * flagship was the statement and the database name, so that is what is asserted absent.
     */
    public function test_a_subject_id_an_integer_keyed_model_cannot_parse_is_a_422(): void
    {
        Bus::fake();

        $this->actingAs(new UuidKeyedUser);

        $response = $this->postJson('/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['ints.happened'],
            'subject_type' => IntKeyedRecord::class,
            'subject_id' => (string) Str::uuid(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('subject_id');

        // ⚠️ The MESSAGE is asserted, not merely the status, and that is what makes this test able to
        // fail against the code it was written for. On sqlite the pre-repair path also answered 422
        // here — `where id = '<uuid>'` simply matches nothing and `find()` returns null — so a
        // status-only assertion is green against the defect and only turns red on Postgres. The
        // distinct message is the one signal that says the id was REFUSED rather than looked up.
        $response->assertJsonPath('errors.subject_id.0', 'Not a usable id for a IntKeyedRecord — that record is keyed by integer.');

        $body = $response->getContent();

        foreach (['SQLSTATE', 'select ', 'insert into', 'invalid input syntax'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, (string) $body);
        }

        $this->assertSame(0, Hook::count(), 'A refused subscription still wrote a row.');
    }

    /** The same refusal for an id that is not even id-shaped — the human-guessed spelling. */
    public function test_a_prose_subject_id_is_a_422_not_a_driver_error(): void
    {
        Bus::fake();

        $this->actingAs(new UuidKeyedUser);

        $this->postJson('/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['ints.happened'],
            'subject_type' => IntKeyedRecord::class,
            'subject_id' => 'the-first-one',
        ])->assertStatus(422)
            ->assertJsonPath('errors.subject_id.0', 'Not a usable id for a IntKeyedRecord — that record is keyed by integer.');
    }

    /**
     * The control. A WELL-FORMED id for a record that does not exist must stay the 422 it already
     * was — if the new key-type gate had swallowed this case, the refusal above would be proving
     * nothing about the gate and everything about `find()` returning null.
     */
    public function test_a_well_formed_but_unknown_subject_id_is_still_the_no_such_record_422(): void
    {
        Bus::fake();

        $this->actingAs(new UuidKeyedUser);

        $response = $this->postJson('/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['ints.happened'],
            'subject_type' => IntKeyedRecord::class,
            'subject_id' => '999999',
        ])->assertStatus(422);

        $this->assertStringContainsString('No such record', (string) $response->getContent());
    }

    /**
     * …and the other control, which is the one that would catch an over-broad refusal: a uuid-keyed
     * subject must still be subscribable. A gate written as "ids are digits" passes every assertion
     * above and breaks the flagship, where every subject is uuid-keyed.
     */
    public function test_a_uuid_keyed_subject_is_still_subscribable(): void
    {
        Bus::fake();

        $this->actingAs(new UuidKeyedUser);

        $subject = UuidKeyedRecord::create(['name' => 'a real record']);

        $id = $this->postJson('/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['uuids.happened'],
            'subject_type' => UuidKeyedRecord::class,
            'subject_id' => $subject->getKey(),
        ])->assertCreated()->json('data.hook.id');

        $hook = Hook::findOrFail($id);

        $this->assertSame($subject->getKey(), $hook->subject_id);
        $this->assertSame(UuidKeyedRecord::class, $hook->subject_type);
    }

    /** An integer-keyed subject, subscribed by its real id, is unaffected by any of the above. */
    public function test_an_integer_keyed_subject_is_still_subscribable(): void
    {
        Bus::fake();

        $this->actingAs(new UuidKeyedUser);

        $subject = IntKeyedRecord::create(['name' => 'a real record']);

        $id = $this->postJson('/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['ints.happened'],
            'subject_type' => IntKeyedRecord::class,
            'subject_id' => (string) $subject->getKey(),
        ])->assertCreated()->json('data.hook.id');

        $this->assertSame('1', Hook::findOrFail($id)->subject_id);
    }
}

class UuidKeyedUser extends User
{
    use HasUuids;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    public function getKey()
    {
        return '9f1c2b4e-0000-4000-8000-000000000001';
    }
}

class IntKeyedUser extends User
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    public function getKey()
    {
        return 1;
    }
}

class IntKeyedRecord extends Model
{
    public $timestamps = false;

    protected $table = 'int_keyed_records';

    protected $guarded = [];
}

class UuidKeyedRecord extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'uuid_keyed_records';

    protected $guarded = [];
}

class MorphProbeData extends Data
{
    public function __construct(public string $id) {}
}
