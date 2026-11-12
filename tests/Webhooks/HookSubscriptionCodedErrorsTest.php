<?php

namespace Splicewire\Beam\Tests\Webhooks;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Events\EventType;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Webhooks\HookSubscriptionError;

/**
 * localization 13 — the exemplar coded-error path: creating a hook subscription. A refusal a client receives carries a
 * stable `code` beside its localized message, and these tests assert the CODE, never the prose (the prose assertions
 * in {@see HookHttpSurfaceTest} stay as the oracle that English did not change).
 *
 * Done-when #1 asks for the pseudo-locale; the pseudo-locale transform ships with localization 06, which waits on
 * config-sections 02. It is proven here with a fixture second locale instead: the message changes and the code does
 * not. The pseudo-locale assertion carries forward to 06/20.
 */
class HookSubscriptionCodedErrorsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('app.key', str_repeat('h', 32));
    }

    protected function getPackageProviders($app): array
    {
        return [FrameServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $migration = require __DIR__.'/../../database/migrations/shared/create_beam_hooks_table.php.stub';
        $migration->up();

        $this->actingAs(new CodedErrorsHookUser);
        Gate::before(fn () => true);
        Bus::fake();

        $this->app->make(EventTypeRegistry::class)
            ->register(new EventType(name: 'tenants.provisioned', subjectless: true, description: 'A tenant finished provisioning.'));
    }

    /** @param  array<string, mixed>  $payload */
    private function refuse(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/frame/resources/hooks', $payload)->assertUnprocessable();
    }

    public function test_each_refusal_carries_its_stable_code_beside_the_message(): void
    {
        $this->refuse(['endpoint' => 'https://receiver.test/inbox'])
            ->assertJsonPath('codes.events.0', 'events_required');

        $this->refuse(['events' => ['tenants.provisioned']])
            ->assertJsonPath('codes.endpoint.0', 'endpoint_required');

        $this->refuse(['endpoint' => 'https://receiver.test/inbox', 'events' => ['tenants.exploded']])
            ->assertJsonPath('codes.events.0', 'events_unknown');

        $this->refuse(['endpoint' => 'https://receiver.test/inbox', 'events' => ['tenants.provisioned'], 'subjectType' => 'users'])
            ->assertJsonPath('codes.subjectType.0', 'subject_pair_incomplete');

        $this->refuse(['endpoint' => 'https://receiver.test/inbox', 'events' => ['tenants.provisioned'], 'subjectType' => 'NoSuchModel', 'subjectId' => '1'])
            ->assertJsonPath('codes.subjectType.0', 'subject_type_unknown');

        $this->assertSame(0, Hook::count());
    }

    public function test_the_errors_map_keeps_its_shape_so_existing_clients_read_it_as_before(): void
    {
        $this->refuse(['endpoint' => 'https://receiver.test/inbox'])
            ->assertJsonStructure(['message', 'errors' => ['events'], 'codes' => ['events']])
            ->assertJsonPath('errors.events.0', 'Name at least one event to subscribe to. GET hooks/events lists them.');
    }

    public function test_a_second_locale_changes_the_message_and_never_the_code(): void
    {
        $lang = sys_get_temp_dir().'/beam-coded-'.getmypid().'-'.bin2hex(random_bytes(4));
        @mkdir("{$lang}/vendor/beam/es", 0777, true);
        file_put_contents("{$lang}/vendor/beam/es/enums.php", '<?php return '.var_export([
            'Splicewire/Beam/Webhooks/HookSubscriptionError' => ['cases' => [
                'EventsRequired' => ['message' => 'Nombra al menos un evento.'],
                'EventsUnknown' => ['message' => 'Fuera del catálogo: :unknown. Nombres válidos: :legal.'],
            ]],
        ], true).';');
        $this->app->useLangPath($lang);
        $this->app->forgetInstance('translation.loader');
        $this->app->forgetInstance('translator');
        $this->app->setLocale('es');

        $this->refuse(['endpoint' => 'https://receiver.test/inbox'])
            ->assertJsonPath('errors.events.0', 'Nombra al menos un evento.')
            ->assertJsonPath('codes.events.0', 'events_required');

        $this->refuse(['endpoint' => 'https://receiver.test/inbox', 'events' => ['tenants.exploded']])
            ->assertJsonPath('errors.events.0', fn (string $m): bool => str_starts_with($m, 'Fuera del catálogo: tenants.exploded. Nombres válidos: ')
                && str_contains($m, 'tenants.provisioned'))
            ->assertJsonPath('codes.events.0', 'events_unknown');

        // A case with no Spanish line falls back to its declared English, with the same code.
        $this->refuse(['events' => ['tenants.provisioned']])
            ->assertJsonPath('errors.endpoint.0', 'An endpoint is required to subscribe.')
            ->assertJsonPath('codes.endpoint.0', 'endpoint_required');
    }

    public function test_a_case_derives_its_key_from_the_case_and_its_code_from_the_value(): void
    {
        $case = HookSubscriptionError::EventsRequired;

        $this->assertSame('events_required', $case->code());
        $this->assertSame('beam::enums.Splicewire/Beam/Webhooks/HookSubscriptionError.cases.EventsRequired.message', $case->translationKey());
        $this->assertSame('Name at least one event to subscribe to. GET hooks/events lists them.', $case->message());
    }
}

class CodedErrorsHookUser extends \Illuminate\Foundation\Auth\User
{
    protected $table = 'users';

    public function getKey()
    {
        return 1;
    }
}
