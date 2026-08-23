<?php

namespace Splicewire\Beam\Tests\Scribe;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Scribe\Strategies\ParticleUrlParameterStrategy;
use Splicewire\Beam\Tests\TestCase;

class UrlFixtureCatalog extends Model
{
    use HasUuids;

    protected $table = 'catalogs';
}

class UrlFixtureSeller extends Model
{
    protected $table = 'sellers';

    public $incrementing = true;

    protected $keyType = 'int';
}

/**
 * api-surface-coherence ticket 26 (deciding ticket 08 §3–§4) — a path parameter documents its type,
 * example and description off the resource the segment names.
 *
 * The assertions that matter are the three the build argued from: the resolving column is the
 * DECLARED `routeKey` falling back to the primary key (never `getRouteKeyName()`, which a particle
 * mount does not consult), examples are DERIVED so they are stable across regenerations, and a miss
 * defers rather than answers.
 */
class ParticleUrlParameterStrategyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(ParticleResourceRegistry::class);
    }

    private function register(?string $routeKey = null, string $key = 'catalogs', string $singularLabel = ''): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: $key,
            backing: UrlFixtureCatalog::class,
            label: 'Studio',
            singularLabel: $singularLabel,
            routeKey: $routeKey,
        ));
    }

    private function endpoint(string $uri, bool $stamped = true, array $wheres = []): ExtractedEndpointData
    {
        $route = new Route(['GET'], $uri, [
            'uses' => ParticleController::class.'@show',
            'controller' => ParticleController::class.'@show',
        ]);

        if ($stamped) {
            $route->defaults(ParticleController::RESOURCE, 'catalogs');
        }

        foreach ($wheres as $parameter => $pattern) {
            $route->where($parameter, $pattern);
        }

        return ExtractedEndpointData::fromRoute($route);
    }

    private function strategy(): ParticleUrlParameterStrategy
    {
        return new ParticleUrlParameterStrategy(new DocumentationConfig([]));
    }

    public function test_a_stamped_subject_documents_a_valid_uuid_and_names_its_resource(): void
    {
        $this->register();

        $parameters = $this->strategy()($this->endpoint('catalogs/{id}'));

        $this->assertSame('string', $parameters[ParticleController::SUBJECT]['type']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $parameters[ParticleController::SUBJECT]['example'],
        );
        $this->assertSame('The UUID of the catalog.', $parameters[ParticleController::SUBJECT]['description']);
    }

    public function test_the_example_is_derived_so_it_does_not_churn_between_runs(): void
    {
        $this->register();

        $first = $this->strategy()($this->endpoint('catalogs/{id}'));
        $second = $this->strategy()($this->endpoint('catalogs/{id}'));

        $this->assertSame(
            $first[ParticleController::SUBJECT]['example'],
            $second[ParticleController::SUBJECT]['example'],
        );
    }

    public function test_an_int_keyed_resource_documents_an_integer(): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: 'sellers',
            backing: UrlFixtureSeller::class,
        ));

        $parameters = $this->strategy()($this->endpoint('sellers/{seller}', stamped: false));

        $this->assertSame('integer', $parameters['seller']['type']);
        $this->assertSame(1, $parameters['seller']['example']);
        $this->assertSame('The ID of the seller.', $parameters['seller']['description']);
    }

    public function test_a_declared_route_key_wins_over_the_primary_key(): void
    {
        $this->register(routeKey: 'slug');

        $parameters = $this->strategy()($this->endpoint('catalogs/{id}'));

        // The model is uuid-keyed; the resource declares it is addressed by slug. The DECLARATION is
        // what `findParticle()` resolves against, so it is what the reference must publish.
        $this->assertSame('string', $parameters[ParticleController::SUBJECT]['type']);
        $this->assertSame('catalog', $parameters[ParticleController::SUBJECT]['example']);
        $this->assertSame('The slug of the catalog.', $parameters[ParticleController::SUBJECT]['description']);
    }

    public function test_a_parent_segment_resolves_without_any_stamp(): void
    {
        $this->register();

        // No stamp at all: `{catalog}` is named by the segment in front of it, which is Scribe's own
        // heuristic pointed at the particle registry instead of `App\Models\*`.
        $parameters = $this->strategy()($this->endpoint('catalogs/{catalog}/items', stamped: false));

        $this->assertSame('The UUID of the catalog.', $parameters['catalog']['description']);
    }

    public function test_a_uuid_constraint_is_read_for_what_it_declares(): void
    {
        $uuid = '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}';

        $parameters = $this->strategy()($this->endpoint('widgets/{id}', stamped: false, wheres: ['id' => $uuid]));

        // A real uuid, where Scribe's `regexify` emits `CdbAAddd-fdCB-Fcfd-EdfB-eBBAabbBCdaE` — which
        // looks like one, is not one, and is the reason "Try it" fails on these routes.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $parameters['id']['example'],
        );

        // No description: nothing here knows what the record is called, and inventing one would
        // overwrite the honest prose Scribe already derived from the URI.
        $this->assertArrayNotHasKey('description', $parameters['id']);
    }

    public function test_a_declared_singular_label_beats_the_key_and_the_label_never_wins(): void
    {
        $this->register(singularLabel: 'Medium item');

        $parameters = $this->strategy()($this->endpoint('catalogs/{id}'));

        // `label` is 'Studio' on this fixture — a SURFACE name, never the record's.
        $this->assertSame('The UUID of the medium item.', $parameters[ParticleController::SUBJECT]['description']);
    }

    public function test_an_unresolvable_route_is_deferred_not_answered(): void
    {
        $this->register();

        // null, not [] — anything else would claim the endpoint and stop Scribe's own strategies.
        $this->assertNull($this->strategy()($this->endpoint('widgets/{widget}', stamped: false)));
    }
}
