<?php

namespace Splicewire\Beam\Tests\Scribe;

use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Scribe\Strategies\ParticleTitleStrategy;
use Splicewire\Beam\Tests\TestCase;

/**
 * A dissolved particle route has no docblock for `GetFromDocBlocks` to summarize, so its docs-sidebar
 * entry fell to the bare path (`/api/v1/agents/{id} POST`) beside the titled hand-written endpoints.
 * The declaration already names the endpoint — resource key/label, op name/kind — so the title strategy
 * derives the summary + description from it, and defers to any explicit docblock summary.
 *
 * Descended from `splicewire/tower` by api-surface-coherence 24, reading beam's own particle registries
 * rather than the empty `Tower\Particle\*` subclasses that used to anchor it two tiers up.
 */
class ParticleTitleStrategyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(ParticleResourceRegistry::class);
        $this->app->singleton(ParticleOperationRegistry::class);
    }

    /** @param  array<string, mixed>  $extraDefaults */
    private function endpoint(string $uri, string $method, string $key, array $extraDefaults = []): ExtractedEndpointData
    {
        $route = (new Route(['GET'], $uri, [
            'uses' => ParticleController::class.'@'.$method,
            'controller' => ParticleController::class.'@'.$method,
        ]))->defaults(ParticleController::RESOURCE, $key);

        foreach ($extraDefaults as $name => $value) {
            $route->defaults($name, $value);
        }

        return ExtractedEndpointData::fromRoute($route);
    }

    private function opEndpoint(string $resource, string $name): ExtractedEndpointData
    {
        $route = (new Route(['POST'], "{$resource}/{id}/op/{$name}", [
            'uses' => ParticleOperationController::class.'@invoke',
            'controller' => ParticleOperationController::class.'@invoke',
        ]))
            ->defaults(ParticleOperationController::RESOURCE, $resource)
            ->defaults(ParticleOperationController::NAME, $name);

        return ExtractedEndpointData::fromRoute($route);
    }

    private function registerResource(string $key, string $label = '', bool $filterable = true, string $singularLabel = ''): void
    {
        app(ParticleResourceRegistry::class)->register(new ParticleResource(
            key: $key,
            model: 'App\\Models\\Fixture',
            label: $label,
            filterable: $filterable,
            frame: false,
            singularLabel: $singularLabel,
        ));
    }

    private function registerOp(string $resource, string $name, OperationKind $kind): void
    {
        app(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: $resource,
            name: $name,
            kind: $kind,
            model: 'App\\Models\\Fixture',
            handle: fn () => null,
        ));
    }

    private function strategy(): ParticleTitleStrategy
    {
        return new ParticleTitleStrategy(new DocumentationConfig([]));
    }

    public function test_crud_verbs_title_off_the_resource_declaration_preferring_label_over_key(): void
    {
        $this->registerResource('agents', label: 'Agents');

        $index = $this->strategy()($this->endpoint('api/v1/agents', 'index', 'agents'));
        $show = $this->strategy()($this->endpoint('api/v1/agents/{id}', 'show', 'agents'));
        $update = $this->strategy()($this->endpoint('api/v1/agents/{id}', 'update', 'agents'));

        $this->assertSame('List Agents', $index['title']);
        $this->assertStringContainsString('Paginated list of agents.', $index['description']);
        $this->assertStringContainsString('filter and sort facets', $index['description']);
        $this->assertSame(['title' => 'Show Agent', 'description' => 'A single agent by id.'], $show);
        $this->assertSame(['title' => 'Update Agent', 'description' => 'Update an agent.'], $update);
    }

    public function test_a_label_less_resource_titles_off_a_headline_of_its_registry_key(): void
    {
        $this->registerResource('runner-transforms', filterable: false);

        $result = $this->strategy()($this->endpoint('api/v1/runner-transforms', 'index', 'runner-transforms'));

        // filterable: false ⇒ no facets note — the description must not promise a filter surface the
        // declaration never opted into.
        $this->assertSame([
            'title' => 'List Runner Transforms',
            'description' => 'Paginated list of runner transforms.',
        ], $result);
    }

    public function test_a_relative_mount_names_its_parent_in_the_title(): void
    {
        $this->registerResource('media');

        $result = $this->strategy()($this->endpoint(
            'api/v1/fragments/{fragment}/media', 'index', 'media',
            [ParticleController::RELATIVE => 'fragment'],
        ));

        $this->assertSame('List Media for a Fragment', $result['title']);
        $this->assertStringContainsString('Scoped through the parent Fragment binding.', $result['description']);
    }

    public function test_a_read_operation_titles_noun_first_and_a_task_verb_first(): void
    {
        $this->registerResource('media', singularLabel: 'Media');
        $this->registerOp('media', 'download', OperationKind::Read);
        $this->registerOp('circuits', 'run', OperationKind::Task);

        $read = $this->strategy()($this->opEndpoint('media', 'download'));
        $task = $this->strategy()($this->opEndpoint('circuits', 'run'));

        // The inflector singularizes `media` to "Medium", which read as a quirk in the docs sidebar
        // ("Medium Download"). The resource declares `singularLabel: 'Media'` — the mass noun IS its own
        // singular here — and the strategy honours the declaration over the inflection.
        $this->assertSame('Media Download', $read['title']);
        $this->assertSame('Read operation `download` on the `media` resource.', $read['description']);
        $this->assertSame('Run Circuit', $task['title']);
        $this->assertStringContainsString('`?async`', $task['description']);
    }

    public function test_a_declared_singular_label_overrides_the_inflected_singular_on_crud_verbs(): void
    {
        $this->registerResource('media', singularLabel: 'Media', filterable: false);

        $show = $this->strategy()($this->endpoint('api/v1/media/{id}', 'show', 'media'));

        // Without the declaration this titled "Show Medium" — the inflector cannot know `media` is a mass
        // noun (`Agents` → `Agent` and `Media` → `Medium` are structurally identical round-trips).
        $this->assertSame('Show Media', $show['title']);
        $this->assertSame('A single media by id.', $show['description']);
    }

    public function test_an_unregistered_resource_still_falls_back_to_the_inflected_singular(): void
    {
        $this->registerOp('media', 'download', OperationKind::Read);

        // No resource declaration ⇒ no singularLabel to honour: the headline-of-key fallback keeps the
        // inflector's "Medium". The fix lives at the DECLARATION, not in a hardcoded exception table.
        $this->assertSame('Medium Download', $this->strategy()($this->opEndpoint('media', 'download'))['title']);
    }

    public function test_an_unregistered_operation_still_titles_off_the_route_defaults(): void
    {
        // A mounted-but-unregistered op is a reportable absence, not a reason to leave a bare path in the
        // sidebar — same call the response strategy makes.
        $result = $this->strategy()($this->opEndpoint('declarations', 'redetermine'));

        $this->assertSame([
            'title' => 'Redetermine Declaration',
            'description' => 'Operation `redetermine` on the `declarations` resource.',
        ], $result);
    }

    public function test_an_explicit_docblock_summary_wins_and_the_strategy_defers(): void
    {
        $this->registerResource('agents', label: 'Agents');
        $endpoint = $this->endpoint('api/v1/agents', 'index', 'agents');
        $endpoint->metadata->title = 'Browse the agent roster';

        $this->assertNull($this->strategy()($endpoint));
    }

    public function test_a_non_particle_route_defers_to_the_docblock_strategies(): void
    {
        $route = new Route(['GET'], 'api/v1/saved-filters', [
            'uses' => ParticleController::class.'@index',
            'controller' => ParticleController::class.'@index',
        ]);

        $this->assertNull($this->strategy()(ExtractedEndpointData::fromRoute($route)));
    }
}
