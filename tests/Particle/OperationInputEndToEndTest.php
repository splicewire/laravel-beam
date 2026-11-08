<?php

namespace Splicewire\Beam\Tests\Particle;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationCall;
use Splicewire\Beam\Particle\OperationContext;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\Emitter;
use Splicewire\Beam\Tests\TestCase;

/**
 * tutorial-dx 03, the runtime half: an operation's input reaches `rules()` and `handle()`.
 *
 * Before: `validateInput()` ran `$input::validate($request->all())` and made no DTO, so every handler re-parsed the request
 * (`::from($request)` / `validateAndCreate`); and `rules()` could not see the subject, so the tutorial re-queried it.
 *
 * The ruled design (orch-lead 2026-11-08, on orch-peer's read): the legacy `($model, $request, $actor[, $emit])` call is
 * unchanged; `rules()` may type-hint an immutable {@see OperationContext} (subject, request, actor), bound for validation;
 * a handler that opts in with ONE {@see OperationCall} parameter gets {context, validated DTO}, plus the emitter as its
 * second argument on a Stream; a Task's job copies the values it needs, never the call.
 */
class OperationInputEndToEndTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ledgers', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });
        Ledger::create(['label' => 'one']);
        Gate::define('tally', fn (?Authenticatable $user, Ledger $ledger) => true);
        TallyInput::$constructed = 0;
    }

    public function test_a_handler_receives_the_validated_dto_without_re_parsing_the_request(): void
    {
        $seen = null;
        $this->mount($this->handlerReceivingInput(function (Model $subject, ?Data $input) use (&$seen) {
            $seen = $input;

            return ['ok' => true];
        }));

        $this->postJson('/ledgers/1/tally', ['count' => '5', 'label' => 'fresh'])->assertOk();

        $this->assertInstanceOf(TallyInput::class, $seen, 'the handler gets the validated input DTO');
        $this->assertSame(5, $seen->count, 'cast by the DTO, not the raw request string');
        $this->assertSame(1, TallyInput::$constructed, 'made once, by the controller; the handler re-parses nothing');
    }

    public function test_the_call_carries_the_subject_request_and_actor(): void
    {
        $call = null;
        $this->mount(function (OperationCall $given) use (&$call) {
            $call = $given;

            return ['ok' => true];
        });

        $this->postJson('/ledgers/1/tally', ['count' => 1, 'label' => 'fresh'])->assertOk();

        $this->assertInstanceOf(Ledger::class, $call->context->subject);
        $this->assertSame(1, $call->context->subject->getKey());
        $this->assertInstanceOf(Request::class, $call->context->request);
        $this->assertNull($call->context->actor);
    }

    public function test_invalid_input_never_reaches_the_handler(): void
    {
        $called = false;
        $this->mount($this->handlerReceivingInput(function () use (&$called) {
            $called = true;

            return [];
        }));

        $this->postJson('/ledgers/1/tally', ['label' => 'fresh'])->assertUnprocessable()->assertJsonValidationErrors('count');
        $this->assertFalse($called);
    }

    public function test_rules_can_see_the_subject(): void
    {
        // TallyInput::rules(OperationContext $operation) refuses a label equal to the subject's own: only possible if it sees the subject.
        $this->mount($this->handlerReceivingInput(fn () => ['ok' => true]));

        $this->postJson('/ledgers/1/tally', ['count' => 1, 'label' => 'one'])->assertUnprocessable()->assertJsonValidationErrors('label');
        $this->postJson('/ledgers/1/tally', ['count' => 1, 'label' => 'two'])->assertOk();
    }

    public function test_rules_see_the_subject_for_a_legacy_handler_too(): void
    {
        $this->mount(fn ($model, $request, $actor) => ['ok' => true]);

        $this->postJson('/ledgers/1/tally', ['count' => 1, 'label' => 'one'])->assertUnprocessable()->assertJsonValidationErrors('label');
        $this->postJson('/ledgers/1/tally', ['count' => 1, 'label' => 'two'])->assertOk();
        $this->assertSame(0, TallyInput::$constructed, 'a legacy handler still gets no DTO made for it');
    }

    public function test_the_three_argument_handler_is_called_exactly_as_before(): void
    {
        $args = null;
        // A plain input class: this guard must hold before and after, so its rules() asks for nothing new.
        $this->mount(function ($model, $request, $actor) use (&$args) {
            $args = [$model, $request, $actor];

            return ['ok' => true];
        }, PlainTallyInput::class);

        $this->postJson('/ledgers/1/tally', ['count' => '5', 'label' => 'fresh'])->assertOk();

        $this->assertInstanceOf(Ledger::class, $args[0]);
        $this->assertInstanceOf(Request::class, $args[1], 'the second argument is still the Request');
        $this->assertSame('5', $args[1]->input('count'), 'the raw request, as before');
        $this->assertNull($args[2]);
    }

    public function test_a_stream_handler_gets_the_call_and_the_emitter(): void
    {
        $seen = [];
        $this->mount(function (OperationCall $call, Emitter $emit) use (&$seen) {
            $seen = [$call->input->count, $emit];
            $emit('tallied', ['count' => $call->input->count]);
        }, kind: OperationKind::Stream);

        $response = $this->postJson('/ledgers/1/tally', ['count' => '3', 'label' => 'fresh']);
        $body = $this->capture(fn () => $response->baseResponse->sendContent());

        $this->assertSame(3, $seen[0]);
        $this->assertInstanceOf(Emitter::class, $seen[1]);
        $this->assertStringContainsString("event: tallied\ndata: {\"count\":3}\n\n", $body);
    }

    public function test_a_task_handler_returns_a_job_that_copies_what_it_needs(): void
    {
        Bus::fake();
        $this->mount(fn (OperationCall $call) => new TallyJob($call->context->subject->getKey(), $call->input->count), kind: OperationKind::Task);

        $this->postJson('/ledgers/1/tally', ['count' => '4', 'label' => 'fresh'])->assertSuccessful();

        Bus::assertDispatched(TallyJob::class, fn (TallyJob $job) => $job->ledgerId === 1 && $job->count === 4);
    }

    public function test_a_task_job_that_captures_the_call_is_refused_before_dispatch(): void
    {
        Bus::fake();
        $this->withoutExceptionHandling();
        $this->mount(fn (OperationCall $call) => new CapturingJob($call), kind: OperationKind::Task);

        try {
            $this->postJson('/ledgers/1/tally', ['count' => '4', 'label' => 'fresh']);
            $this->fail('a job carrying the per-request call must not be dispatched');
        } catch (LogicException $refused) {
            $this->assertStringContainsString('must not capture', $refused->getMessage());
        }

        Bus::assertNotDispatched(CapturingJob::class);
    }

    // ── orch-peer's REVISE (2026-11-08) ────────────────────────────────────────────────────────────────

    public function test_the_dto_is_built_from_the_validated_payload_not_the_raw_request(): void
    {
        // NoteInput's rules exclude `note` unless count is 10: a value the rules did not admit must not reach the DTO.
        $seen = null;
        $this->mount(function (OperationCall $call) use (&$seen) {
            $seen = $call->input;

            return ['ok' => true];
        }, NoteInput::class);

        $this->postJson('/ledgers/1/tally', ['count' => '5', 'note' => 'smuggled', 'extra' => 'nope'])->assertOk();

        $this->assertInstanceOf(NoteInput::class, $seen);
        $this->assertNull($seen->note, 'an excluded field never reaches the DTO');
        $this->assertSame(['count' => 5, 'note' => null], $seen->toArray(), 'nor does a field no rule names');

        $this->postJson('/ledgers/1/tally', ['count' => '10', 'note' => 'admitted'])->assertOk();
        $this->assertSame('admitted', $seen->note, 'an admitted field still arrives');
    }

    /** @return array<string, array{Closure(OperationCall): object}> */
    public static function capturingJobs(): array
    {
        return [
            'three levels down' => [fn (OperationCall $call) => new DeepJob(['wrapped' => new Holder($call)])],
            'inside a serializable closure' => [fn (OperationCall $call) => new ClosureJob(new SerializableClosure(fn () => $call->input))],
            'the context alone, deep' => [fn (OperationCall $call) => new DeepJob(['a' => ['b' => new Holder($call->context)]])],
            'a plain closure (cannot be queued at all)' => [fn (OperationCall $call) => new ClosureJob(fn () => 1)],
        ];
    }

    #[DataProvider('capturingJobs')]
    public function test_a_job_capturing_the_call_anywhere_in_its_graph_is_refused(Closure $makeJob): void
    {
        Bus::fake();
        $this->withoutExceptionHandling();
        $this->mount(fn (OperationCall $call) => $makeJob($call), kind: OperationKind::Task);

        try {
            $this->postJson('/ledgers/1/tally', ['count' => '4', 'label' => 'fresh']);
            $this->fail('a job carrying the per-request call must not be dispatched');
        } catch (LogicException $refused) {
            $this->assertStringContainsString('must not capture', $refused->getMessage());
        }

        Bus::assertNothingDispatched();
    }

    /** @return array<string, array{Closure}> */
    public static function misspelledOptIns(): array
    {
        return [
            'a union' => [fn (OperationCall|Ledger $call) => null],
            'a nullable' => [fn (?OperationCall $call) => null],
            'a later parameter' => [fn ($model, OperationCall $call) => null],
        ];
    }

    #[DataProvider('misspelledOptIns')]
    public function test_an_opt_in_spelled_any_other_way_is_refused_not_read_as_legacy(Closure $handle): void
    {
        try {
            OperationCall::wantedBy($handle);
            $this->fail('the handler names OperationCall, so it must not silently fall through to the legacy call');
        } catch (LogicException $refused) {
            $this->assertStringContainsString('typed exactly `OperationCall $call`', $refused->getMessage());
        }
    }

    public function test_a_union_typed_handler_is_refused_at_the_request(): void
    {
        $this->withoutExceptionHandling();
        $this->mount(fn (OperationCall|Ledger $call) => ['ok' => true]);

        $this->expectException(LogicException::class);
        $this->postJson('/ledgers/1/tally', ['count' => 1, 'label' => 'fresh']);
    }

    // ── the ruled design's spelling ────────────────────────────────────────────────────────────────────

    /** A handler that receives the validated input: `$use($subject, $input, $actor)`, through ONE OperationCall parameter. */
    private function handlerReceivingInput(Closure $use): Closure
    {
        return fn (OperationCall $call) => $use($call->context->subject, $call->input, $call->context->actor);
    }

    /** @param  class-string<Data>  $input */
    private function mount(Closure $handle, string $input = TallyInput::class, OperationKind $kind = OperationKind::Write): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'ledgers',
            name: 'tally',
            kind: $kind,
            model: Ledger::class,
            handle: $handle,
            ability: 'tally',
            input: $input,
        ));
        Particle::ops('ledgers', 'ledgers', 'tally', ['method' => 'post']);
    }

    /** Capture streamed output across the emitter's own `ob_flush` (nest one extra buffer level), as StreamOperationTest does. */
    private function capture(callable $stream): string
    {
        ob_start();
        ob_start();
        $stream();
        $inner = (string) ob_get_clean();

        return (string) ob_get_clean().$inner;
    }
}

class Ledger extends Model
{
    protected $table = 'ledgers';

    protected $guarded = [];

    public $timestamps = false;
}

class PlainTallyInput extends Data
{
    public function __construct(
        public int $count,
        public string $label,
    ) {}
}

class TallyInput extends Data
{
    public static int $constructed = 0;

    public function __construct(
        public int $count,
        public string $label,
    ) {
        self::$constructed++;
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(OperationContext $operation): array
    {
        return [
            'count' => ['required', 'integer'],
            'label' => ['required', 'string', Rule::notIn([$operation->subject->getAttribute('label')])],
        ];
    }
}

class TallyJob
{
    public function __construct(
        public int $ledgerId,
        public int $count,
    ) {}
}

class CapturingJob
{
    public function __construct(
        public OperationCall $call,
    ) {}
}

class NoteInput extends Data
{
    public function __construct(
        public int $count,
        public ?string $note = null,
    ) {}

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return ['count' => ['required', 'integer'], 'note' => ['exclude_unless:count,10', 'nullable', 'string']];
    }
}

class DeepJob
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public array $payload,
    ) {}
}

class Holder
{
    public function __construct(
        private object $held,
    ) {}
}

class ClosureJob
{
    public function __construct(
        public mixed $after,
    ) {}
}
