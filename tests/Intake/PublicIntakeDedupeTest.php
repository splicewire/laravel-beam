<?php

namespace Splicewire\Beam\Tests\Intake;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Http\PublicIntakeController;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;
use Splicewire\Beam\Schema\Keywords;
use Splicewire\Beam\Tests\TestCase;

/**
 * `x-beam-dedupe` at the HTTP seam (beam-facade ticket 66) — the one place the door's own
 * indistinguishability obligation is observable, because it is a property of the RESPONSE BODY and
 * not of the write pipeline.
 *
 * It caught a live defect while being written: {@see PublicIntakeController}
 * reported `$record->getKey()` off the instance it had constructed, not off the model the writer
 * handed back. Under `ignore` those are different objects — the writer returns the row that MATCHED
 * — so a repeat would have answered with the unsaved instance's key. That is exactly the
 * email-existence oracle ticket 50 §6 forbids, arriving through the success body rather than
 * through a status code.
 *
 * Deliberately its own file rather than a case in {@see PublicIntakeRouteTest}: that suite builds
 * its schemas through the versioned-identity fixture generator and every one of its tests is
 * currently dark on `MissingSchemaBaseUri` (beam-facade ticket 85). This one stubs the target
 * resolver, so it is a live gate on the door today.
 */
class PublicIntakeDedupeTest extends TestCase
{
    private const STEM = 'waitlist-intake';

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Config is read at boot, so the door must be mounted pre-boot.
        $app['config']->set('beam.core.intake.enabled', true);
        $app['config']->set('beam.core.intake.forms', ['waitlist' => self::STEM]);
        $app['config']->set('beam.core.intake.public_schemas', [self::STEM]);
        $app['config']->set('beam.core.intake.throttle', '60,1');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(SchemaTargetResolver::class, fn () => new class implements SchemaTargetResolver
        {
            public function targetFor(string $recordType, ?int $version = null): array
            {
                if ($recordType !== PublicIntakeDedupeTest::stem()) {
                    return [];
                }

                return [
                    'type' => 'object',
                    'required' => ['email'],
                    'properties' => ['email' => ['type' => 'string']],
                    // `ignore` — the mode a PUBLIC door wants. `reject` is an oracle by
                    // construction (the 409 is the disclosure), so it is legitimate only behind an
                    // authenticated door.
                    Keywords::Dedupe => ['by' => ['email'], 'mode' => 'ignore'],
                ];
            }
        });

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
    }

    /** The stem the stub resolver answers for, reachable from the anonymous class above. */
    public static function stem(): string
    {
        return self::STEM;
    }

    /**
     * THE SECURITY PROPERTY at the door: a repeat under `ignore` must be byte-identical to a fresh
     * capture — same status, same body — or an anonymous caller probes an address and learns from
     * the response whether it is already on the list.
     */
    public function test_a_repeat_capture_is_byte_identical_to_a_fresh_one(): void
    {
        $fresh = $this->postJson('beam/intake/waitlist', ['email' => 'ada@example.test']);
        $repeat = $this->postJson('beam/intake/waitlist', ['email' => 'ada@example.test']);

        $fresh->assertStatus(201);
        $repeat->assertStatus(201);
        $this->assertSame($fresh->json(), $repeat->json());

        // And the id it returns is a REAL row — the ledger holds exactly one capture.
        $this->assertDatabaseCount(Beam::table('submissions'), 1);
        $this->assertDatabaseHas(Beam::table('submissions'), ['id' => $repeat->json('id')]);
    }

    /** A different address is a different capture: the door still captures normally. */
    public function test_a_distinct_capture_still_lands_and_answers_with_its_own_id(): void
    {
        $first = $this->postJson('beam/intake/waitlist', ['email' => 'ada@example.test']);
        $second = $this->postJson('beam/intake/waitlist', ['email' => 'grace@example.test']);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertNotSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseCount(Beam::table('submissions'), 2);
    }

    /** Casefold and trim reach the door, so a re-signup under a different casing is one capture. */
    public function test_the_repeat_is_matched_after_trimming_and_casefolding(): void
    {
        $this->postJson('beam/intake/waitlist', ['email' => 'Ada@Example.TEST'])->assertStatus(201);
        $this->postJson('beam/intake/waitlist', ['email' => '  ada@example.test '])->assertStatus(201);

        $this->assertDatabaseCount(Beam::table('submissions'), 1);
    }
}
