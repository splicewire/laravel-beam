<?php

namespace Splicewire\Beam\Tests\Feature;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Foundation\Auth\User;
use Illuminate\Pagination\CursorPaginator as Paginator;
use Schemastud\Frame\FrameServiceProvider;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Filters\DeclaredResourceQuery;
use Splicewire\Beam\Particle\Backing\DeclaredFacet;
use Splicewire\Beam\Particle\Backing\DeclaresFilterVocabulary;
use Splicewire\Beam\Particle\Backing\FilterVocabulary;
use Splicewire\Beam\Particle\Backing\StreamsRecords;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

class StreamFilterExecutionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [FrameServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('frame.middleware', []);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs((new User)->forceFill(['id' => 1]));
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'boolean-stream', backing: BooleanStreamBacking::class, data: BooleanStreamRow::class,
            frame: true, readOnly: true, showable: false,
        ));
        Particle::mount('boolean-stream', 'boolean-stream')->only(['index']);
    }

    public function test_false_and_zero_filters_select_the_same_rows_in_both_transports(): void
    {
        foreach (['boolean-stream', 'frame/resources/boolean-stream'] as $route) {
            $this->getJson($route)->assertOk()->assertJsonCount(2, 'data');
            foreach (['0', 'false'] as $value) {
                $this->getJson($route.'?filter[enabled]='.$value)
                    ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'disabled');
            }
            $this->getJson($route.'?filter[enabled]=1')
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 'enabled');
        }
    }

    public function test_streaming_lists_reject_an_unadvertised_variant(): void
    {
        foreach (['boolean-stream', 'frame/resources/boolean-stream'] as $route) {
            $this->getJson($route.'?filterVariant=unknown')->assertNotFound();
        }
    }

    public function test_both_list_transports_reject_a_query_declared_on_a_stream(): void
    {
        app(ParticleResourceRegistry::class)->get('boolean-stream')->query = DeclaredResourceQuery::class;
        foreach (['boolean-stream', 'frame/resources/boolean-stream'] as $route) {
            $this->getJson($route)->assertStatus(500);
        }
    }
}

class BooleanStreamBacking implements DeclaresFilterVocabulary, StreamsRecords
{
    public function filterVocabulary(): FilterVocabulary
    {
        return FilterVocabulary::of(DeclaredFacet::exact('enabled'));
    }

    public function records(array $filters, ?string $cursor, int $perPage): CursorPaginator
    {
        $rows = [new BooleanStreamRow('disabled', false), new BooleanStreamRow('enabled', true)];
        if (array_key_exists('enabled', $filters)) {
            $enabled = filter_var($filters['enabled'], FILTER_VALIDATE_BOOLEAN);
            $rows = array_values(array_filter($rows, fn (BooleanStreamRow $row) => $row->enabled === $enabled));
        }

        return new Paginator($rows, $perPage);
    }
}

class BooleanStreamRow extends Data
{
    public function __construct(public string $id, public bool $enabled) {}
}
