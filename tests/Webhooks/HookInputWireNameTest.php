<?php

namespace Splicewire\Beam\Tests\Webhooks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Every subject-shaped spelling that is not the declared camel pair, plus any other undeclared key, is a 422 naming
     * the key, and NO hook is written. An ignored narrowing key would otherwise widen the subscription to the whole
     * resource (build-qa 18:33Z: mixed-case and nested spellings were silently dropped and persisted a broad hook).
     *
     * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function refusedSubjectShapes(): array
    {
        return [
            'exact snake' => [['subject_type' => HookWireNameRecord::class, 'subject_id' => '1'], ['subject_type', 'subject_id']],
            'snake half' => [['subject_type' => HookWireNameRecord::class], ['subject_type']],
            'mixed case' => [['subject_Type' => HookWireNameRecord::class, 'subject_Id' => '1'], ['subject_Type', 'subject_Id']],
            'nested' => [['subject' => ['type' => HookWireNameRecord::class, 'id' => '1']], ['subject']],
            'camel plus snake' => [['subjectType' => HookWireNameRecord::class, 'subjectId' => '1', 'subject_type' => HookWireNameRecord::class, 'subject_id' => '1'], ['subject_type', 'subject_id']],
            'unknown extra key' => [['subjectType' => HookWireNameRecord::class, 'subjectId' => '1', 'audience' => 'everyone'], ['audience']],
        ];
    }

    #[DataProvider('refusedSubjectShapes')]
    public function test_undeclared_input_keys_are_refused_and_no_hook_is_written(array $extra, array $refused): void
    {
        Bus::fake();
        $this->actingAs((new User)->forceFill(['id' => 1]));
        HookWireNameRecord::query()->create();

        $response = $this->postJson('/frame/resources/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['wire-names.happened'],
            ...$extra,
        ])->assertUnprocessable()->assertJsonValidationErrors($refused);

        $this->assertEqualsCanonicalizing($refused, array_keys($response->json('errors')));
        $this->assertSame(0, Hook::query()->count());
    }

    public function test_the_declared_input_names_are_camel_whatever_the_host_mapper(): void
    {
        $properties = app(DataConfig::class)->getDataClass(HookInputData::class)->properties;

        $this->assertSame('subjectType', $properties->get('subjectType')->inputMappedName);
        $this->assertSame('subjectId', $properties->get('subjectId')->inputMappedName);
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
