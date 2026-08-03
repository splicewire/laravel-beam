<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * The REST controller must honour a resource's `scope` closure — the row-level read gate (ADR-0156 §83).
 * Without it a `filterable:false` index returns every row across all callers, and a resolve-by-id reaches
 * rows the caller may not touch. These assertions pin BOTH gates (the leak this closed).
 */
class ParticleControllerScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scope_widgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->string('name');
        });

        ScopeWidget::create(['owner_id' => 1, 'name' => 'mine-a']);
        ScopeWidget::create(['owner_id' => 1, 'name' => 'mine-b']);
        ScopeWidget::create(['owner_id' => 2, 'name' => 'theirs']);

        // A non-filterable resource scoped to owner 1's rows only.
        $this->app->make(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'scope-widget',
            model: ScopeWidget::class,
            data: ScopeWidgetData::class,
            filterable: false,
            scope: fn (Builder $q) => $q->where('owner_id', 1),
        ));
    }

    private function requestFor(string $uri): Request
    {
        $request = Request::create($uri);
        $route = new Route('GET', $uri, []);
        $route->defaults(ParticleController::RESOURCE, 'scope-widget');
        $request->setRouteResolver(fn () => $route);

        return $request;
    }

    public function test_a_non_filterable_index_is_gated_by_scope(): void
    {
        $response = $this->app->make(ParticleController::class)
            ->index($this->requestFor('/scope-widgets'))
            ->toResponse($this->requestFor('/scope-widgets'));

        $data = $response->getData(true)['data'];

        // Only owner 1's two rows — owner 2's 'theirs' must not leak.
        $this->assertCount(2, $data);
        $this->assertEqualsCanonicalizing(['mine-a', 'mine-b'], array_column($data, 'name'));
    }

    public function test_resolve_by_id_is_gated_by_scope(): void
    {
        $theirs = ScopeWidget::where('name', 'theirs')->firstOrFail();

        $this->expectException(ModelNotFoundException::class);

        // findParticle scopes the base query, so another owner's row 404s (never resolves).
        (new ExposedParticleController(
            $this->app->make(\Splicewire\Beam\Write\ParticleWriter::class),
            $this->app->make(\Splicewire\Beam\Read\Contracts\ParticleHydrator::class),
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(\Splicewire\Beam\Http\Contracts\ResponseEnvelope::class),
        ))->resolve('scope-widget', (string) $theirs->id);
    }

    public function test_an_owned_id_resolves(): void
    {
        $mine = ScopeWidget::where('name', 'mine-a')->firstOrFail();

        $resolved = (new ExposedParticleController(
            $this->app->make(\Splicewire\Beam\Write\ParticleWriter::class),
            $this->app->make(\Splicewire\Beam\Read\Contracts\ParticleHydrator::class),
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(\Splicewire\Beam\Http\Contracts\ResponseEnvelope::class),
        ))->resolve('scope-widget', (string) $mine->id);

        $this->assertSame('mine-a', $resolved->name);
    }
}

class ScopeWidget extends Model
{
    public $timestamps = false;

    protected $table = 'scope_widgets';

    protected $guarded = [];
}

class ScopeWidgetData extends \Spatie\LaravelData\Data
{
    public function __construct(public int $id, public string $name) {}
}

/** Exposes the protected findParticle for the resolve-by-id scope assertions. */
class ExposedParticleController extends ParticleController
{
    public function resolve(string $key, string $id): Model
    {
        return $this->findParticle($this->registry->get($key), $id);
    }
}
