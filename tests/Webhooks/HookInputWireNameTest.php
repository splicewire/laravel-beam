<?php

namespace Splicewire\Beam\Tests\Webhooks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Schemastud\Frame\FrameServiceProvider;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Support\DataConfig;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Data\HookInputData;
use Splicewire\Beam\Events\EventType;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

class HookInputWireNameTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', str_repeat('h', 32));
        $app['config']->set('data.name_mapping_strategy.input', CamelCaseMapper::class);
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

        Schema::create('wire_name_records', function (Blueprint $table): void {
            $table->id();
        });

        Gate::before(fn () => true);

        $this->app->make(EventTypeRegistry::class)->register(new EventType(
            name: 'wire-names.happened',
            subjectless: true,
            description: 'A wire-name probe fired.',
        ));
        $this->app->make(ParticleResourceRegistry::class)->registerClass(HookData::class);
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'wire-name-records',
            backing: HookWireNameRecord::class,
            data: HookWireNameRecordData::class,
        ));
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            Schema::dropIfExists('wire_name_records');
        }

        parent::tearDown();
    }

    /**
     * Integrator ruling 18:11Z (ticket 00, option a): the package DECLARES its wire names. `subject_type` and
     * `subject_id` carry #[MapName], as SavedFilter*Data do, so a host's global CamelCaseMapper cannot float
     * the contract. This test runs on exactly such a camel host.
     */
    public function test_subject_errors_use_the_declared_snake_wire_name_on_a_camel_host(): void
    {
        Bus::fake();
        $this->actingAs((new User)->forceFill(['id' => 1]));

        $response = $this->postJson('/frame/resources/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['wire-names.happened'],
            'subject_type' => HookWireNameRecord::class,
            'subject_id' => 'not-an-integer',
        ])->assertUnprocessable()->assertJsonValidationErrors(['subject_id']);

        $this->assertSame(['subject_id'], array_keys($response->json('errors')));
    }

    public function test_a_snake_subject_pair_narrows_the_hook_on_a_camel_host(): void
    {
        Bus::fake();
        $this->actingAs((new User)->forceFill(['id' => 1]));
        $record = HookWireNameRecord::query()->create();

        $this->postJson('/frame/resources/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['wire-names.happened'],
            'subject_type' => HookWireNameRecord::class,
            'subject_id' => (string) $record->getKey(),
        ])->assertSuccessful();

        $hook = Hook::query()->sole();
        $this->assertSame(HookWireNameRecord::class, $hook->subject_type);
        $this->assertSame((string) $record->getKey(), (string) $hook->subject_id);
    }

    public function test_the_declared_input_names_are_snake_whatever_the_host_mapper(): void
    {
        $properties = app(DataConfig::class)
            ->getDataClass(HookInputData::class)->properties;

        $this->assertSame('subject_type', $properties->get('subject_type')->inputMappedName);
        $this->assertSame('subject_id', $properties->get('subject_id')->inputMappedName);
    }
}

class HookWireNameRecord extends Model
{
    public $timestamps = false;

    protected $table = 'wire_name_records';
}

class HookWireNameRecordData extends Data
{
    public function __construct(public int $id) {}
}
