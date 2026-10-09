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
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Events\EventType;
use Splicewire\Beam\Events\EventTypeRegistry;
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

    public function test_subject_errors_use_the_camel_wire_name_accepted_by_the_host(): void
    {
        Bus::fake();
        $this->actingAs((new User)->forceFill(['id' => 1]));

        $response = $this->postJson('/frame/resources/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['wire-names.happened'],
            'subjectType' => HookWireNameRecord::class,
            'subjectId' => 'not-an-integer',
        ])->assertUnprocessable()->assertJsonValidationErrors(['subjectId']);

        $this->assertSame(['subjectId'], array_keys($response->json('errors')));
    }
}

class HookWireNameRecord extends Model
{
    protected $table = 'wire_name_records';
}

class HookWireNameRecordData extends Data
{
    public function __construct(public int $id) {}
}
