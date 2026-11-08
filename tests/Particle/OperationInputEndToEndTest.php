<?php

namespace Splicewire\Beam\Tests\Particle;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use LogicException;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * tutorial-dx 03, the runtime half: an operation's input reaches `rules()` and `handle()`.
 *
 * Before: `validateInput()` ran `$input::validate($request->all())` and made no DTO, so every handler re-parsed the request
 * (`::from($request)` / `validateAndCreate`); and `rules()` could not see the subject, so the tutorial re-queried it.
 *
 * Two designs for HOW a handler and `rules()` ask for these are under review (tdx03-design.md). Every test body below is
 * design-independent; the one design seam is {@see OperationInputSeam} and {@see self::handlerReceivingInput()}.
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
        // TallyInput::rules() refuses a label equal to the subject's own: only possible if rules() sees the subject.
        $this->mount($this->handlerReceivingInput(fn () => ['ok' => true]));

        $this->postJson('/ledgers/1/tally', ['count' => 1, 'label' => 'one'])->assertUnprocessable()->assertJsonValidationErrors('label');
        $this->postJson('/ledgers/1/tally', ['count' => 1, 'label' => 'two'])->assertOk();
    }

    public function test_the_three_argument_handler_is_called_exactly_as_before(): void
    {
        $args = null;
        // A plain input class: this guard must hold today and under either design, so its rules() asks for nothing new.
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

    // ── the design seam (tdx03-design.md) ─────────────────────────────────────────────────────────────

    /**
     * A handler that receives the validated input, as the RULED design spells it; `$use($subject, $input, $actor)`.
     * Until the design is ruled this throws, so every test that needs it is red for the right reason.
     */
    private function handlerReceivingInput(Closure $use): Closure
    {
        throw new LogicException('tutorial-dx 03: the handler interface is not yet ruled (Design 1 or 2, tdx03-design.md)');
    }

    /** @param  class-string<Data>  $input */
    private function mount(Closure $handle, string $input = TallyInput::class): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'ledgers',
            name: 'tally',
            kind: OperationKind::Write,
            model: Ledger::class,
            handle: $handle,
            ability: 'tally',
            input: $input,
        ));
        Particle::ops('ledgers', 'ledgers', 'tally');
    }
}

/** How `rules()` reads the operation's subject, as the RULED design spells it (tdx03-design.md). */
final class OperationInputSeam
{
    public static function subject(): Model
    {
        throw new LogicException('tutorial-dx 03: how rules() reads the subject is not yet ruled (Design 1 or 2, tdx03-design.md)');
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
    public static function rules(): array
    {
        return [
            'count' => ['required', 'integer'],
            'label' => ['required', 'string', Rule::notIn([OperationInputSeam::subject()->getAttribute('label')])],
        ];
    }
}
