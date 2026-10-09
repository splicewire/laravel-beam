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

/**
 * Owner ruling 2026-10-09 18:11Z/18:18Z: the ecosystem DTO wire-name standard is camelCase, greenfield, with no aliases.
 * HookInputData DECLARES `subjectType`/`subjectId` (#[MapName], beam's per-property convention, as SavedFilter*Data),
 * so the contract is the same on every host. This class runs on a CAMEL-mapper host;
 * {@see HookInputWireNameNoMapperHostTest} runs the same cases on a host with NO input mapper.
 */
class HookInputWireNameTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', str_repeat('h', 32));
        $app['config']->set('data.name_mapping_strategy.input', $this->hostInputMapper());
    }

    /** The host's global laravel-data input mapper under test. */
    protected function hostInputMapper(): ?string
    {
        return CamelCaseMapper::class;
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

    public function test_subject_errors_use_the_declared_camel_wire_names(): void
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

    public function test_a_camel_subject_pair_narrows_the_hook(): void
    {
        Bus::fake();
        $this->actingAs((new User)->forceFill(['id' => 1]));
        $record = HookWireNameRecord::query()->create();

        $this->postJson('/frame/resources/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['wire-names.happened'],
            'subjectType' => HookWireNameRecord::class,
            'subjectId' => (string) $record->getKey(),
        ])->assertSuccessful();

        $hook = Hook::query()->sole();
        $this->assertSame(HookWireNameRecord::class, $hook->subject_type);
        $this->assertSame((string) $record->getKey(), (string) $hook->subject_id);
    }

    public function test_snake_subject_names_are_refused_not_aliased(): void
    {
        Bus::fake();
        $this->actingAs((new User)->forceFill(['id' => 1]));
        $record = HookWireNameRecord::query()->create();

        // No alias, and not silently ignored either: an ignored narrowing key would broaden the subscription.
        $response = $this->postJson('/frame/resources/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['wire-names.happened'],
            'subject_type' => HookWireNameRecord::class,
            'subject_id' => (string) $record->getKey(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['subject_type', 'subject_id']);

        $this->assertSame(["Unknown input; this field's wire name is subjectType."], $response->json('errors.subject_type'));
        $this->assertSame(0, Hook::query()->count());
    }

    public function test_the_declared_input_names_are_camel_whatever_the_host_mapper(): void
    {
        $properties = app(DataConfig::class)->getDataClass(HookInputData::class)->properties;

        $this->assertSame('subjectType', $properties->get('subject_type')->inputMappedName);
        $this->assertSame('subjectId', $properties->get('subject_id')->inputMappedName);
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
