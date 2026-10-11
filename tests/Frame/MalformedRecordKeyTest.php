<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Schemastud\Frame\FrameServiceProvider;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/** A malformed UUID is a missing record, never a database error or an existence signal. */
class MalformedRecordKeyTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', str_repeat('k', 32));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('auth.providers.users.model', MalformedKeyActor::class);
        $app['config']->set('frame.middleware', ['web', 'auth']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('malformed_key_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
        });

        Gate::policy(MalformedKeyRecord::class, MalformedKeyPolicy::class);

        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'malformed-key-records',
            backing: MalformedKeyRecord::class,
            data: MalformedKeyData::class,
            scope: fn ($query) => $query,
            label: 'Malformed key records',
        ));

        $this->actingAs(new MalformedKeyActor);
    }

    /** @return iterable<string, array{string}> */
    public static function recordVerbs(): iterable
    {
        yield 'show' => ['get'];
        yield 'update' => ['put'];
        yield 'delete' => ['delete'];
    }

    #[DataProvider('recordVerbs')]
    public function test_a_malformed_uuid_is_404_before_frame_queries_the_backing(string $verb): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'malformed_key_records')) {
                $queries[] = $query->sql;
            }
        });

        $response = match ($verb) {
            'get' => $this->getJson('/frame/resources/malformed-key-records/records/not-a-uuid'),
            'put' => $this->putJson('/frame/resources/malformed-key-records/records/not-a-uuid', ['name' => 'Changed']),
            'delete' => $this->deleteJson('/frame/resources/malformed-key-records/records/not-a-uuid'),
        };

        $response->assertNotFound();
        $this->assertSame([], $queries, 'A malformed UUID must be rejected before it reaches the database.');
    }

    #[DataProvider('recordVerbs')]
    public function test_a_declared_route_key_does_not_disable_the_primary_key_guard_used_by_frame(string $verb): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'malformed-key-records',
            backing: MalformedKeyRecord::class,
            data: MalformedKeyData::class,
            scope: fn ($query) => $query,
            label: 'Malformed key records',
            routeKey: 'name',
        ));

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'malformed_key_records')) {
                $queries[] = $query->sql;
            }
        });

        $response = match ($verb) {
            'get' => $this->getJson('/frame/resources/malformed-key-records/records/not-a-uuid'),
            'put' => $this->putJson('/frame/resources/malformed-key-records/records/not-a-uuid', ['name' => 'Changed']),
            'delete' => $this->deleteJson('/frame/resources/malformed-key-records/records/not-a-uuid'),
        };

        $response->assertNotFound();
        $this->assertSame([], $queries, 'Frame resolves records by primary key even when the resource declares a route key.');
    }

    public function test_a_malformed_uuid_and_a_valid_missing_uuid_have_the_same_not_found_status(): void
    {
        $this->getJson('/frame/resources/malformed-key-records/records/not-a-uuid')->assertNotFound();
        $this->getJson('/frame/resources/malformed-key-records/records/'.Str::uuid())->assertNotFound();
    }

    public function test_a_valid_existing_uuid_still_resolves_for_every_frame_record_verb(): void
    {
        $record = MalformedKeyRecord::create(['name' => 'Before']);
        $path = "/frame/resources/malformed-key-records/records/{$record->getKey()}";

        $this->getJson($path)->assertOk()->assertJsonPath('data.name', 'Before');
        $this->putJson($path, ['name' => 'After'])->assertOk()->assertJsonPath('data.name', 'After');
        $this->deleteJson($path)->assertNoContent();

        $this->assertNull(MalformedKeyRecord::find($record->getKey()));
    }

    public function test_a_uuid_trait_on_a_non_primary_column_does_not_constrain_an_integer_primary_key(): void
    {
        Schema::create('integer_key_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name');
        });

        Gate::policy(IntegerKeyRecord::class, IntegerKeyPolicy::class);
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'integer-key-records',
            backing: IntegerKeyRecord::class,
            data: IntegerKeyData::class,
            scope: fn ($query) => $query,
            label: 'Integer key records',
        ));

        $record = IntegerKeyRecord::create(['name' => 'Integer primary key']);

        $this->getJson("/frame/resources/integer-key-records/records/{$record->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Integer primary key');
    }
}

class MalformedKeyRecord extends Model
{
    use HasUuids;

    protected $table = 'malformed_key_records';

    protected $guarded = [];

    public $timestamps = false;
}

class MalformedKeyActor extends User {}

class MalformedKeyData extends Data
{
    public function __construct(public string $id, public string $name) {}
}

class IntegerKeyRecord extends Model
{
    use HasUuids;

    protected $table = 'integer_key_records';

    protected $guarded = [];

    public $timestamps = false;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}

class IntegerKeyData extends Data
{
    public function __construct(public int $id, public string $name) {}
}

class MalformedKeyPolicy
{
    public function viewAny(Authenticatable $actor): bool
    {
        return true;
    }

    public function view(Authenticatable $actor, MalformedKeyRecord $record): bool
    {
        return true;
    }

    public function create(Authenticatable $actor): bool
    {
        return true;
    }

    public function update(Authenticatable $actor, MalformedKeyRecord $record): bool
    {
        return true;
    }

    public function delete(Authenticatable $actor, MalformedKeyRecord $record): bool
    {
        return true;
    }
}

class IntegerKeyPolicy
{
    public function viewAny(Authenticatable $actor): bool
    {
        return true;
    }

    public function view(Authenticatable $actor, IntegerKeyRecord $record): bool
    {
        return true;
    }
}
