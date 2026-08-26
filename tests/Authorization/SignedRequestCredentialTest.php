<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * `signed:` — a validly-signed request is itself a credential (api-surface-coherence ticket 95).
 *
 * ## The failure this pins, which happened TWICE on one operation
 *
 * `beam-accounts`' `users.login-as` is mounted `GET users/{id}/op/login-as` and reached by a
 * short-lived link `URL::temporarySignedRoute()` mints. Before this slot existed, beam could express
 * neither half of what that implies, and the gap surfaced through two unrelated-looking slots:
 *
 *  - **`ability:`** would have 403'd the link. `AbilityResolver` only ever asks about an ACTOR and the
 *    link's holder is anonymous by construction, so the operation hand-rolled its entire gate in the
 *    handler and shipped `ability: null` — one of only two operations in the estate still undeclared.
 *  - **`input:`** would have 422'd the link. The signer appends `?expires=…&signature=…`;
 *    `rejectInput()` asked the KIND what the framework accepts, `OperationKind::frameworkParameters()`
 *    answers `[]` for everything but a Task, so both reserved keys `array_diff`'d out as unexpected
 *    caller payload. That is why `users.login-as` was the second of the two deliberate `input: null`s
 *    blocking the `null` ⇒ `false` flip.
 *
 * One declaration closes both, and this file asserts both halves plus the two refusals that keep it
 * from being a bypass: an INVALID signature admits nothing, and an op that did not declare `signed:`
 * is unaffected in either direction.
 *
 * ## Scheme, secret, rotation, replay — stated, because a credential with an unstated one is a hazard
 *
 * No new scheme: Laravel's, unchanged — `hash_hmac('sha256', $url, config('app.key'))`, `hash_equals`
 * compared, minted by `URL::temporarySignedRoute()` and verified by `Request::hasValidSignature()`.
 * The secret is the host's `APP_KEY`; rotation is an `APP_KEY` rotation and invalidates every
 * outstanding link at once, with no per-link revocation. Replay is BOUNDED BY EXPIRY, not prevented —
 * whoever holds the URL before `expires` may use it repeatedly. Single-use would need a per-link
 * consumption ledger, which beam has no store for; the mint-time TTL is the whole control.
 */
class SignedRequestCredentialTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sigils', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });

        Sigil::create(['label' => 'one']);

        // Nobody passes. The point of every admitting assertion below is that the SIGNATURE got in,
        // not that the policy was lenient.
        Gate::define('assume', fn (?User $user, Sigil $sigil) => false);
    }

    // ── half one: the signature satisfies `ability:` ────────────────────────────────────────────────

    public function test_a_validly_signed_request_admits_an_anonymous_caller_past_a_declared_ability(): void
    {
        $this->mount($this->op(ability: 'assume', signed: true));

        $this->get($this->signedUrl())->assertOk()->assertJson(['ran' => true]);
    }

    public function test_the_same_operation_still_denies_an_unsigned_caller_the_policy_refuses(): void
    {
        // Sufficient, not required. The signature is an ADDITIONAL credential; removing it puts the
        // caller back on the ability plane, which is where the authenticated-operator half lives.
        $this->mount($this->op(ability: 'assume', signed: true));

        $this->get('/sigils/1/op/assume')->assertForbidden();
    }

    public function test_a_tampered_signature_admits_nothing(): void
    {
        $this->mount($this->op(ability: 'assume', signed: true));

        $this->get($this->signedUrl().'0')->assertForbidden();
    }

    public function test_an_expired_signature_admits_nothing(): void
    {
        $this->mount($this->op(ability: 'assume', signed: true));

        $url = URL::temporarySignedRoute('sigils.op.assume', now()->addMinute(), ['id' => 1]);

        $this->travelTo(now()->addMinutes(2));

        $this->get($url)->assertForbidden();
    }

    public function test_an_operation_that_did_not_declare_signed_is_not_admitted_by_a_signature(): void
    {
        // The slot is opt-in and the default is refusal, which is what stops `signed:` from being a
        // global bypass anyone holding a valid URL for ANY route could ride.
        $this->mount($this->op(ability: 'assume', signed: false));

        $this->get($this->signedUrl())->assertForbidden();
    }

    // ── half two: the signer's parameters are the FRAMEWORK's, not the caller's ─────────────────────

    public function test_a_signed_op_declaring_input_false_accepts_the_signers_own_parameters(): void
    {
        $this->mount($this->op(ability: 'assume', signed: true, input: false));

        $this->get($this->signedUrl())->assertOk();
    }

    public function test_a_signed_op_declaring_input_false_still_rejects_real_caller_input(): void
    {
        // The forgiveness is exactly two named keys wide. `input: false` has to keep meaning what it
        // says, or closing ticket 95 would have opened a hole in ticket 30. Reached on the UNSIGNED
        // path (declared-ungated) for the reason the next test measures.
        $this->mount($this->op(name: 'poke', ability: false, signed: true, input: false));

        $this->getJson('/sigils/1/op/poke?colour=red')->assertStatus(422);
    }

    public function test_extra_query_input_on_a_signed_url_invalidates_the_signature_before_the_input_guard_sees_it(): void
    {
        // MEASURED while writing this file, and worth stating because it is stronger than the guard
        // it pre-empts: Laravel hashes the WHOLE query string minus `signature`/`expires`, so a caller
        // who appends a parameter to a signed link breaks the signature rather than smuggling input
        // past `input: false`. The response is a 403 from the ability plane, not a 422 — the signed
        // axis is tamper-evident end to end, and `rejectInput()` is never reached on it at all.
        $this->mount($this->op(ability: 'assume', signed: true, input: false));

        $this->getJson($this->signedUrl().'&colour=red')->assertForbidden();
    }

    public function test_an_unsigned_op_declaring_input_false_still_treats_the_signing_keys_as_input(): void
    {
        // The union is a fact of the DECLARATION, so an op that never accepts a signed credential has
        // no reason to forgive the pair — and a stray `?signature=` there is caller input like any
        // other. Asserted so the fix cannot quietly widen into "everyone forgives these two keys".
        $this->mount($this->op(name: 'prod', ability: false, signed: false, input: false));

        $this->getJson('/sigils/1/op/prod?signature=abc')->assertStatus(422);
    }

    public function test_the_framework_parameter_list_is_the_operations_and_unions_the_kinds(): void
    {
        $this->assertSame([], $this->op()->frameworkParameters());
        $this->assertSame(['expires', 'signature'], $this->op(signed: true)->frameworkParameters());

        // A signed Task carries both contributions — the enum's `?async` and the signer's pair.
        $this->assertSame(
            ['async', 'expires', 'signature'],
            $this->op(kind: OperationKind::Task, signed: true)->frameworkParameters(),
        );
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────────────

    private function signedUrl(): string
    {
        return URL::temporarySignedRoute('sigils.op.assume', now()->addMinutes(30), ['id' => 1]);
    }

    private function op(
        string $name = 'assume',
        OperationKind $kind = OperationKind::Write,
        string|false|null $ability = 'assume',
        string|false|null $input = null,
        bool $signed = false,
    ): ParticleOperation {
        return new ParticleOperation(
            resource: 'sigils',
            name: $name,
            kind: $kind,
            model: Sigil::class,
            handle: fn () => ['ran' => true],
            ability: $ability,
            input: $input,
            signed: $signed,
        );
    }

    private function mount(ParticleOperation $operation): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register($operation);

        // GET, because that is the mount the whole defect lives on: a browser follows a signed link,
        // so a Write-kind operation is mounted GET and `rejectInput()` therefore reads the QUERY
        // string. The kind/method mismatch is the affordance, not a bug — see the ticket.
        Route::particleOp('sigils', 'sigils', $operation->name, ['method' => 'get']);
    }
}

class Sigil extends Model
{
    protected $table = 'sigils';

    public $timestamps = false;

    protected $guarded = [];
}
