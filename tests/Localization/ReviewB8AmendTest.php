<?php

namespace Splicewire\Beam\Tests\Localization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Data\ResponseBody;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Localization\Locales;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\Localization\StrictSweepInputData;
use Splicewire\Beam\Tests\TestCase;

/**
 * REVIEW-B8 (Codex, review-r1): the one amend for localization 21. Each case is the review's repro, plus the controls it
 * asked for:
 * - B1: a coded refusal keeps its `codes` through ParticleController::parseInput;
 * - B3: the fixed generic fallback, `Server error.`, is keyed, and both helpers keep their shape and safety.
 * B2 (the intake's code) is in PublicIntakeRouteTest, which owns the production route's setup.
 */
class ReviewB8AmendTest extends TestCase
{
    private function jsonRequest(): \Illuminate\Http\Request
    {
        return request()->duplicate(server: ['HTTP_ACCEPT' => 'application/json']);
    }

    public function test_b1_an_unknown_dto_field_keeps_its_code_on_the_wire_with_unchanged_english(): void
    {
        $this->actingAs(new ReviewB8AmendUser);
        Gate::before(fn () => true);
        Schema::create('review_b8_entries', function (Blueprint $t): void {
            $t->id();
            $t->string('wireName')->nullable();
        });
        app(ParticleResourceRegistry::class)->register(new ParticleResource(key: 'review-b8', backing: ReviewB8AmendEntry::class, data: ReviewB8AmendEntryData::class, input: StrictSweepInputData::class));
        Particle::mount('review-b8', 'review-b8')->only(['store']);

        $response = $this->postJson('/review-b8', ['wireName' => 'valid', 'wire_name' => 'bad'])->assertStatus(422);
        $this->assertSame(['Unknown input field [wire_name].'], $response->json('errors.wire_name'));
        $this->assertSame(['unknown_input_field'], $response->json('codes.wire_name'));
        $this->assertIsString($response->json('message'));
    }

    public function test_b1_a_plain_validation_failure_keeps_laravels_shape_with_no_codes_member(): void
    {
        $this->actingAs(new ReviewB8AmendUser);
        Gate::before(fn () => true);
        Schema::create('review_b8_entries', function (Blueprint $t): void {
            $t->id();
            $t->string('wireName')->nullable();
        });
        app(ParticleResourceRegistry::class)->register(new ParticleResource(key: 'review-b8', backing: ReviewB8AmendEntry::class, data: ReviewB8AmendEntryData::class, input: StrictSweepInputData::class));
        Particle::mount('review-b8', 'review-b8')->only(['store']);

        $response = $this->postJson('/review-b8', [])->assertStatus(422);
        $this->assertSame(['message', 'errors'], array_keys((array) $response->json()));
    }

    public function test_b3_the_generic_fallback_is_unchanged_in_english_and_marked_under_pseudo(): void
    {
        $this->assertSame('Server error.', ResponseBody::exception(new RuntimeException)->toResponse($this->jsonRequest())->getData(true)['message']);

        config(['beam.localization.pseudo' => true]);
        app()->setLocale(Locales::PSEUDO);
        $message = ResponseBody::exception(new RuntimeException)->toResponse($this->jsonRequest())->getData(true)['message'];
        $this->assertTrue(PseudoLocale::isMarked($message), $message);
    }

    public function test_b3_an_exceptions_own_message_is_never_turned_into_a_key(): void
    {
        config(['beam.localization.pseudo' => true]);
        app()->setLocale(Locales::PSEUDO);

        $this->assertSame('disk full', ResponseBody::exception(new RuntimeException('disk full'))->toResponse($this->jsonRequest())->getData(true)['message']);
    }

    public function test_b3_the_last_resort_encoder_falls_back_to_the_keyed_text_and_never_throws_without_a_translator(): void
    {
        $body = new ReviewB8AmendUnencodable;

        // A healthy app: the keyed fallback (English unchanged).
        $this->assertSame('Server error.', json_decode((string) $body->toResponse($this->jsonRequest())->getContent(), true)['message']);

        // A broken translator must not break the last resort: the raw English stands.
        $this->app->bind('translator', fn () => throw new RuntimeException('translator down'));
        $this->assertSame('Server error.', json_decode((string) $body->toResponse($this->jsonRequest())->getContent(), true)['message']);
    }
}

class ReviewB8AmendEntry extends Model
{
    protected $table = 'review_b8_entries';

    protected $guarded = [];

    public $timestamps = false;
}

class ReviewB8AmendEntryData extends Data
{
    public function __construct(public int $id, public ?string $wireName = null) {}
}

/** A body whose encoding fails at every stage, so the minimal envelope with the generic fallback answers. */
class ReviewB8AmendUnencodable
{
    use \Splicewire\Beam\Data\RendersJsonSafely;

    public function toResponse($request): \Illuminate\Http\JsonResponse
    {
        return $this->jsonResponseThatCannotThrow(['message' => null, 'value' => INF], 500);
    }

    protected function toJsonSafeValue(mixed $value, int $depth = 0): mixed
    {
        throw new RuntimeException('stage 2 fails too');
    }
}

class ReviewB8AmendUser extends User
{
    public function getKey()
    {
        return 1;
    }
}
