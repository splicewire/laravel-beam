<?php

namespace Splicewire\Beam\Tests\Frame;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Schemastud\Frame\FrameServiceProvider;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Data\Concerns\RejectsUnknownInputKeys;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

class EditRowIdentityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), FrameServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('frame.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('edit_identity_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Gate::before(fn () => true);
        $this->actingAs(new User);
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'edit-identity-records',
            backing: EditIdentityRecord::class,
            data: EditIdentityInput::class,
            editData: EditIdentityInput::class,
            input: EditIdentityInput::class,
            scope: fn ($query) => $query,
            label: 'Edit identity records',
        ));
    }

    public function test_projected_edit_row_can_be_put_back_unchanged(): void
    {
        $record = EditIdentityRecord::create(['name' => 'Before']);
        $path = '/frame/resources/edit-identity-records/records/'.$record->id;
        $row = $this->getJson($path)->assertOk()->json('data');
        $this->assertSame(['name' => 'Before', 'id' => $record->id], $row);
        $this->putJson($path, $row)->assertOk()->assertJsonPath('data.name', 'Before');
        $this->assertSame('Before', $record->fresh()->name);
    }

    public function test_string_identity_and_an_absent_identity_still_allow_updates(): void
    {
        $record = EditIdentityRecord::create(['name' => 'Before']);
        $path = '/frame/resources/edit-identity-records/records/'.$record->id;
        $this->putJson($path, ['id' => (string) $record->id, 'name' => 'After'])->assertOk();
        $this->putJson($path, ['name' => 'Again'])->assertOk();
        $this->assertSame('Again', $record->fresh()->name);
    }

    #[DataProvider('invalidIdentities')]
    public function test_mismatched_or_malformed_identity_is_rejected_without_writing(mixed $id): void
    {
        $record = EditIdentityRecord::create(['name' => 'Before']);
        $this->putJson('/frame/resources/edit-identity-records/records/'.$record->id, ['id' => $id, 'name' => 'After'])
            ->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->assertSame('Before', $record->fresh()->name);
        $this->assertDatabaseCount('edit_identity_records', 1);
    }

    public static function invalidIdentities(): iterable
    {
        yield 'different record' => [2];
        yield 'null' => [null];
        yield 'boolean' => [true];
        yield 'array' => [[1]];
        yield 'numeric coercion' => ['1e0'];
        yield 'leading zero' => ['01'];
    }

    public function test_other_unknown_fields_remain_strict_and_store_does_not_accept_id(): void
    {
        $record = EditIdentityRecord::create(['name' => 'Before']);
        $this->putJson('/frame/resources/edit-identity-records/records/'.$record->id,
            ['id' => $record->id, 'name' => 'After', 'surprise' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('surprise');
        $this->postJson('/frame/resources/edit-identity-records', ['id' => 2, 'name' => 'New'])
            ->assertUnprocessable()->assertJsonValidationErrors('id');
        $this->assertSame('Before', $record->fresh()->name);
        $this->assertDatabaseCount('edit_identity_records', 1);
    }
}

class EditIdentityRecord extends Model
{
    protected $table = 'edit_identity_records';

    protected $guarded = [];

    public $timestamps = false;
}

class EditIdentityInput extends BeamData
{
    use RejectsUnknownInputKeys;

    public function __construct(public string $name) {}
}
