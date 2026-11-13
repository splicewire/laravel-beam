<?php

namespace Splicewire\Beam\Tests\Localization;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Authorization\AccessError;
use Splicewire\Beam\Collections\CollectionError;
use Splicewire\Beam\Collections\CollectionValidateStage;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Localization\Catalog\KeyCatalog;
use Splicewire\Beam\Localization\CodedAuthorizationException;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\CodedNotFoundHttpException;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Localization\Locales;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Localization\Ratchet\LiteralTextCensus;
use Splicewire\Beam\Particle\ParticleRequestError;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\Fixtures\Localization\StrictSweepInputData;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Write\Dedupe\DuplicateRejected;
use Splicewire\Beam\Write\PayloadRejected;
use Splicewire\Beam\Write\Stages\ValidateStage;
use Splicewire\Beam\Write\WriteContext;
use Splicewire\Beam\Write\WriteNotAuthorized;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * localization 21 — the laravel-beam sweep. Every message this package can return is a coded error with a key in beam's
 * namespace: under the pseudo-locale each one is marked, its code stays the case's value, and the English is unchanged.
 * The wire cases send a real request and read the response.
 */
class BeamSweepTest extends TestCase
{
    /** @return list<CodedError> every case the sweep introduced */
    private function cases(): array
    {
        return [...CollectionError::cases(), ...ParticleRequestError::cases(), ...AccessError::cases()];
    }

    private function pseudo(): void
    {
        config(['beam.localization.pseudo' => true]);
        $this->app->setLocale(Locales::PSEUDO);
    }

    public function test_every_message_this_package_can_return_is_marked_under_the_pseudo_locale(): void
    {
        $this->pseudo();

        foreach ($this->cases() as $case) {
            $message = $case->message(['accepted' => 'json', 'operation' => 'op', 'field' => 'f', 'resource' => 'r', 'action' => 'a', 'key' => 'k', 'schema' => 's', 'type' => 't']);
            $this->assertTrue(PseudoLocale::isMarked($message), $case::class.'::'.$case->name.': '.$message);
            $this->assertStringNotContainsString(':', preg_replace('/:\w+/', '', $message) === $message ? '' : $message, 'every parameter is replaced');
        }
    }

    public function test_every_case_derives_a_beam_key_and_keeps_its_code(): void
    {
        foreach ($this->cases() as $case) {
            $this->assertStringStartsWith('beam::enums.', (string) $case->translationKey());
            $this->assertSame($case->value, $case->code());
        }
    }

    public function test_an_unknown_input_field_is_refused_with_its_code_and_unchanged_english(): void
    {
        try {
            StrictSweepInputData::from(['wireName' => 'kept', 'wire_name' => 'refused']);
            $this->fail('An undeclared input key should have been refused.');
        } catch (CodedValidationException $exception) {
            $this->assertSame(['Unknown input field [wire_name].'], $exception->errors()['wire_name']);
            $this->assertSame(['wire_name' => ['unknown_input_field']], $exception->codes());
        }
    }

    public function test_a_resource_that_accepts_no_input_answers_with_codes_on_the_wire_and_marked_under_pseudo(): void
    {
        $this->actingAs(new SweepUser);
        Gate::before(fn () => true);
        Schema::create('sweep_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('state')->nullable();
        });
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'sweep-entries', backing: SweepEntry::class, data: SweepEntryData::class, input: false,
        ));
        Particle::mount('sweep-entries', 'sweep-entries')->only(['store']);

        $this->postJson('/sweep-entries', ['state' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The `sweep-entries` resource accepts no input.')
            ->assertJsonPath('errors.state', 'The `sweep-entries` resource accepts no input.')
            ->assertJsonPath('codes.state.0', 'resource_accepts_no_input');

        $this->pseudo();
        $message = $this->postJson('/sweep-entries', ['state' => 'x'])->assertStatus(422)->json('message');
        $this->assertTrue(PseudoLocale::isMarked($message), $message);
        $this->assertStringContainsString('sweep-entries', $message);
    }

    public function test_a_coded_404_stays_a_not_found_exception_and_carries_its_code_for_json(): void
    {
        $exception = new CodedNotFoundHttpException(CollectionError::SchemaHasNoCollection);

        $this->assertInstanceOf(NotFoundHttpException::class, $exception);
        $this->assertSame(404, $exception->getStatusCode());
        $this->assertSame('The current schema does not declare a Collection.', $exception->getMessage());
        $this->assertSame('schema_has_no_collection', $exception->errorCode());

        $response = $exception->render(request()->duplicate(server: ['HTTP_ACCEPT' => 'application/json']));
        $this->assertSame(['message' => 'The current schema does not declare a Collection.', 'code' => 'schema_has_no_collection'], $response?->getData(true));
    }

    public function test_a_coded_http_error_resolves_its_json_message_at_render_in_the_requests_locale(): void
    {
        $exception = new CodedNotFoundHttpException(ParticleRequestError::IntakeSchemaUnknown, ['schema' => 'contact']);
        $this->pseudo();

        $response = $exception->render(request()->duplicate(server: ['HTTP_ACCEPT' => 'application/json']));
        $body = (array) $response?->getData(true);
        $this->assertTrue(PseudoLocale::isMarked((string) $body['message']), (string) $body['message']);
        $this->assertStringContainsString('contact', (string) $body['message']);
        $this->assertSame('intake_schema_unknown', $body['code']);
    }

    public function test_a_rejected_collection_payload_is_coded_with_its_record_type_and_unchanged_english(): void
    {
        $validate = Mockery::mock(ValidateStage::class);
        $validate->shouldReceive('handle')->andThrow(PayloadRejected::for('blog.post'));

        try {
            (new CollectionValidateStage($validate))->handle(new WriteContext(new SweepEntry, []), fn (WriteContext $c): WriteContext => $c);
            $this->fail('A rejected payload should have been refused.');
        } catch (CodedValidationException $exception) {
            $this->assertSame([PayloadRejected::for('blog.post')->getMessage()], $exception->errors()['fields']);
            $this->assertSame(['fields' => ['payload_rejected']], $exception->codes());
        }
    }

    public function test_an_invalid_public_submission_keeps_its_english(): void
    {
        $this->assertSame('The submission for [contact] is invalid.', ParticleRequestError::IntakeSubmissionInvalid->message(['schema' => 'contact']));
    }

    public function test_a_coded_authorization_refusal_is_still_an_authorization_exception_with_unchanged_english_and_a_403_code(): void
    {
        $refusals = [
            [new CodedAuthorizationException(AccessError::WorkspaceIdentityUntrusted), 'Workspace-shared particles require a trusted workspace identity.', 'workspace_identity_untrusted'],
            [new CodedAuthorizationException(AccessError::WorkspaceUninitialized), 'Workspace-shared particles require an initialized workspace.', 'workspace_uninitialized'],
            [new CodedAuthorizationException(AccessError::HookPopulationUnqueryable, ['key' => 'notes']), "The subject's population for [notes] cannot be queried.", 'hook_population_unqueryable'],
            [new CodedAuthorizationException(AccessError::HookOutsideReadScope, ['key' => 'notes']), "The subject is outside [notes]'s read scope.", 'hook_outside_read_scope'],
            [WriteNotAuthorized::for(new SweepEntry), 'The write gate refused a write to ['.SweepEntry::class.'].', 'write_not_authorized'],
        ];

        foreach ($refusals as [$refusal, $english, $code]) {
            // Existing catches hold: each one is caught as a plain AuthorizationException.
            try {
                throw $refusal;
            } catch (AuthorizationException $caught) {
                $this->assertSame($english, $caught->getMessage());
            }
            $response = $refusal->render(request()->duplicate(server: ['HTTP_ACCEPT' => 'application/json']));
            $this->assertSame(403, $response?->getStatusCode());
            $this->assertSame(['message' => $english, 'code' => $code], $response?->getData(true));
            $this->assertNull($refusal->render(request()->duplicate(server: ['HTTP_ACCEPT' => 'text/html'])), 'a browser request renders as before');
        }
        $this->assertSame(404, (new CodedAuthorizationException(AccessError::WorkspaceUninitialized))->asNotFound()->getStatusCode(), 'withStatus/asNotFound still set the status');
    }

    public function test_a_rejected_duplicate_stays_a_409_conflict_with_unchanged_english_and_its_code(): void
    {
        $rejected = DuplicateRejected::for(new SweepEntry);
        $english = 'A capture matching an earlier one was refused for ['.SweepEntry::class.'].';

        $this->assertInstanceOf(ConflictHttpException::class, $rejected);
        $this->assertSame(409, $rejected->getStatusCode());
        $this->assertSame($english, $rejected->getMessage());
        $response = $rejected->render(request()->duplicate(server: ['HTTP_ACCEPT' => 'application/json']));
        $this->assertSame(409, $response?->getStatusCode());
        $this->assertSame(['message' => $english, 'code' => 'duplicate_rejected'], $response?->getData(true));
    }

    public function test_an_authorization_refusal_answers_a_json_request_with_its_code_through_the_framework_handler(): void
    {
        \Illuminate\Support\Facades\Route::get('/sweep-forbidden', fn () => throw new CodedAuthorizationException(AccessError::WorkspaceUninitialized));

        $this->getJson('/sweep-forbidden')->assertStatus(403)
            ->assertExactJson(['message' => 'Workspace-shared particles require an initialized workspace.', 'code' => 'workspace_uninitialized']);
        $this->get('/sweep-forbidden')->assertStatus(403);
    }

    public function test_beams_explicit_resource_registry_lines_are_catalogued_and_marked_under_the_pseudo_locale(): void
    {
        $lines = KeyCatalog::flatten((array) require dirname(__DIR__, 2).'/lang/en/resource-registry.php');
        $catalog = $this->app->make(KeyCatalog::class)->byKey();
        $this->pseudo();

        $this->assertCount(14, $lines);
        foreach ($lines as $item => $english) {
            $key = 'beam::resource-registry.'.$item;
            $this->assertSame($english, $catalog[$key]->english ?? null, $key.' is in the catalog with its English');
            $this->assertTrue(PseudoLocale::isMarked((string) __($key)), $key);
        }
    }

    public function test_the_packages_php_ratchet_reads_zero(): void
    {
        $this->assertSame([], LiteralTextCensus::count(dirname(__DIR__, 2)));
    }
}

class SweepEntry extends Model
{
    protected $table = 'sweep_entries';

    protected $guarded = [];

    public $timestamps = false;
}

class SweepEntryData extends Data
{
    public function __construct(public int $id, public ?string $state = null) {}
}

class SweepUser extends User
{
    public function getKey()
    {
        return 1;
    }
}
