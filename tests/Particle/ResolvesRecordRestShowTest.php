<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Authorization\AuthenticatedReadPolicy;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\Backing\BacksModel;
use Splicewire\Beam\Particle\Backing\QueriesRecords;
use Splicewire\Beam\Particle\Backing\ResolvedRecord;
use Splicewire\Beam\Particle\Backing\ResolvesRecord;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

final class ResolvesRecordRestShowData extends Data
{
    public function __construct(public string $id, public string $title) {}
}

final class ResolvesRecordRestShowModel extends Model
{
    protected $table = 'ag18_model_records';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;
}

final class ResolvesRecordRestShowBacking implements ResolvesRecord
{
    public function resolve(string $id, array $filters): ?ResolvedRecord
    {
        return $id === 'present'
            ? new ResolvedRecord(new ResolvesRecordRestShowData($id, 'Resolved over REST'), 'https://schemas.test/resolved.json')
            : null;
    }
}

final class ResolvesAndQueriesRecordRestShowBacking implements BacksModel, QueriesRecords, ResolvesRecord
{
    public static bool $resolverCalled = false;

    public function modelClass(): string
    {
        return ResolvesRecordRestShowModel::class;
    }

    public function query(array $filters): Builder
    {
        return ResolvesRecordRestShowModel::query();
    }

    public function resolve(string $id, array $filters): ?ResolvedRecord
    {
        self::$resolverCalled = true;

        return new ResolvedRecord(new ResolvesRecordRestShowData($id, 'Resolver path must not run'));
    }
}

final class ResolvesRecordRestShowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ag18_model_records', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function test_it_serves_a_resolves_record_only_backing_over_rest_with_read_authorization_and_schema_ref(): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'resolved-records',
            backing: ResolvesRecordRestShowBacking::class,
            data: ResolvesRecordRestShowData::class,
            input: false,
            readOnly: true,
            readPolicy: AuthenticatedReadPolicy::class,
        ));

        Route::get('/resolved-records/{id}', [ParticleController::class, 'show'])
            ->defaults(ParticleController::RESOURCE, 'resolved-records');

        $this->getJson('/resolved-records/present')->assertForbidden();

        $this->actingAs(new GenericUser(['id' => 'reader']));
        $this->getJson('/resolved-records/present')
            ->assertOk()
            ->assertJsonPath('data.id', 'present')
            ->assertJsonPath('data.title', 'Resolved over REST')
            ->assertJsonPath('data.schemaRef', 'https://schemas.test/resolved.json');
        $this->getJson('/resolved-records/missing')->assertNotFound();
    }

    public function test_a_resolver_that_also_queries_records_keeps_the_model_lookup_path(): void
    {
        Gate::define('view', fn () => true);
        ResolvesAndQueriesRecordRestShowBacking::$resolverCalled = false;
        ResolvesRecordRestShowModel::create(['id' => 'model-row', 'title' => 'Model path']);
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'model-records',
            backing: ResolvesAndQueriesRecordRestShowBacking::class,
            data: ResolvesRecordRestShowData::class,
            input: false,
            readOnly: true,
            readPolicy: AuthenticatedReadPolicy::class,
        ));
        Route::get('/model-records/{id}', [ParticleController::class, 'show'])
            ->defaults(ParticleController::RESOURCE, 'model-records');

        $this->actingAs(new GenericUser(['id' => 'reader']));
        $this->getJson('/model-records/model-row')
            ->assertOk()
            ->assertJsonPath('data.id', 'model-row')
            ->assertJsonPath('data.title', 'Model path');

        $this->assertFalse(ResolvesAndQueriesRecordRestShowBacking::$resolverCalled);
    }

    public function test_a_guest_can_read_only_the_declared_public_projection_of_a_resolved_record(): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'public-resolved-records',
            backing: ResolvesRecordRestShowBacking::class,
            data: ResolvesRecordRestShowData::class,
            publicRead: ResolvesRecordRestShowData::class,
            input: false,
            readOnly: true,
        ));
        Route::get('/public-resolved-records/{id}', [ParticleController::class, 'show'])
            ->defaults(ParticleController::RESOURCE, 'public-resolved-records');

        $this->getJson('/public-resolved-records/present')
            ->assertOk()
            ->assertJsonPath('data.id', 'present')
            ->assertJsonPath('data.title', 'Resolved over REST');
    }
}
