<?php

namespace Splicewire\Beam\Tests\Scribe;

use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Splicewire\Beam\Scribe\Strategies\RouteTitleStrategy;
use Splicewire\Beam\Tests\TestCase;

class RouteTitleFixtureController
{
    public function handle() {}
}

/**
 * The hand-written thin controllers (saved-filters, json-schemas, frame routes, beam/accounts, studio, …)
 * carry no docblock summary and no particle declaration, so their sidebar entries stayed the bare path.
 * The fallback strategy derives an HONEST title from what the route itself declares — route name first,
 * URI + method otherwise — and registers LAST so explicit docblocks and both declaration strategies
 * always win.
 *
 * Descended from `splicewire/tower` by api-surface-coherence 24 — the strategy has no Splicewire imports
 * at all, so tower was holding generic route titling that every beam host wants out of the box. Converted
 * from Pest to PHPUnit on the way down: tower runs Pest, beam runs PHPUnit, and a second runner for four
 * files is a worse trade than a mechanical conversion.
 */
class RouteTitleStrategyTest extends TestCase
{
    private function endpoint(string $method, string $uri, ?string $name = null): ExtractedEndpointData
    {
        $route = new Route([$method], $uri, [
            'uses' => RouteTitleFixtureController::class.'@handle',
            'controller' => RouteTitleFixtureController::class.'@handle',
        ]);

        if ($name !== null) {
            $route->name($name);
        }

        return ExtractedEndpointData::fromRoute($route);
    }

    private function title(string $method, string $uri, ?string $name = null): ?string
    {
        $strategy = new RouteTitleStrategy(new DocumentationConfig([]));

        return $strategy($this->endpoint($method, $uri, $name))['title'] ?? null;
    }

    // ── route-name derivation ─────────────────────────────────────────────

    public function test_a_named_custom_action_titles_verb_first_off_the_route_name(): void
    {
        $this->assertSame(
            'Apply Saved Filter',
            $this->title('GET', 'api/v1/saved-filters/{id}/apply', 'api.v1.saved-filters.apply'),
        );
    }

    public function test_named_crud_actions_map_to_their_conventional_verbs(): void
    {
        $this->assertSame('List Saved Filters', $this->title('GET', 'api/v1/saved-filters', 'saved_filters.index'));
        $this->assertSame('Create Saved Filter', $this->title('POST', 'api/v1/saved-filters', 'saved_filters.store'));
        $this->assertSame('Show Json Schema', $this->title('GET', 'api/v1/json-schemas/{id}', 'json_schemas.show'));
        $this->assertSame('Update Saved Filter', $this->title('PUT', 'api/v1/saved-filters/{id}', 'saved_filters.update'));
        $this->assertSame('Delete Saved Filter', $this->title('DELETE', 'api/v1/saved-filters/{id}', 'saved_filters.destroy'));
        $this->assertSame('Create Idea', $this->title('POST', 'api/v1/studio/ideas', 'studio.ideas.create'));
    }

    public function test_a_multi_word_verb_action_interleaves_the_resource_noun_after_the_verb(): void
    {
        $this->assertSame(
            'Update Member Role',
            $this->title('PUT', 'api/v1/beam/accounts/members/{id}/role', 'beam.accounts.members.update-role'),
        );
    }

    public function test_a_custom_verb_acts_on_the_plural_for_a_get_collection_and_the_singular_otherwise(): void
    {
        $this->assertSame('Preview Bills', $this->title('GET', 'api/v1/bills/preview', 'bills.preview'));
        $this->assertSame('Request Isolated Database', $this->title('POST', 'api/v1/isolated-database/request', 'isolated_database.request'));
        $this->assertSame('Resolve Guest Token', $this->title('GET', 'api/v1/t/{token}', 'guest-tokens.resolve'));
        $this->assertSame('Execute Tenant Sync', $this->title('POST', 'api/v1/tenant-syncs/{sync}/execute', 'api.v1.tenant-syncs.execute'));
    }

    public function test_an_action_that_is_not_a_known_verb_titles_noun_first(): void
    {
        $this->assertSame('Route Manifest', $this->title('GET', 'api/v1/routes', 'routes.manifest'));
        $this->assertSame('Usage Summary', $this->title('GET', 'api/v1/usage/summary', 'usage.summary'));
        $this->assertSame('Circuit Intake', $this->title('POST', 'api/v1/circuits/{id}/intake', 'circuits.intake'));
        $this->assertSame('Commerce Budget', $this->title('GET', 'api/v1/beam/commerce/budget', 'beam.commerce.budget'));
    }

    public function test_a_single_segment_name_is_a_bare_verb_or_a_bare_noun_with_a_synthesized_verb(): void
    {
        $this->assertSame('Search', $this->title('GET', 'api/v1/search', 'search'));
        $this->assertSame('Show Me', $this->title('GET', 'api/v1/me', 'me'));
    }

    public function test_nested_segments_append_for_a_parent(): void
    {
        $this->assertSame('List Runs for a Circuit', $this->title('GET', 'api/v1/circuits/{circuit}/runs', 'circuits.runs.index'));
        $this->assertSame(
            'Delete Guest Token for a Circuit',
            $this->title('DELETE', 'api/v1/circuits/{circuit}/guest-tokens/{id}', 'circuits.guest-tokens.destroy'),
        );
    }

    public function test_an_item_action_tail_never_fabricates_a_parent_out_of_its_own_binding(): void
    {
        $this->assertSame(
            'Complete Checkout',
            $this->title('POST', 'api/v1/agentic-commerce/checkout_sessions/{id}/complete', 'commerce.checkout.complete'),
        );
        $this->assertSame('Accept Invitation', $this->title('POST', 'api/v1/invitations/{token}/accept', 'api.v1.invitations.accept'));
    }

    // ── URI + method derivation (nameless routes) ─────────────────────────

    public function test_a_nameless_route_derives_its_crud_verb_from_the_uri_shape_and_method(): void
    {
        $this->assertSame('List Saved Filters', $this->title('GET', 'api/v1/saved-filters'));
        $this->assertSame('Create Saved Filter', $this->title('POST', 'api/v1/saved-filters'));
        $this->assertSame('Show Saved Filter', $this->title('GET', 'api/v1/saved-filters/{id}'));
        $this->assertSame('Update Saved Filter', $this->title('PUT', 'api/v1/saved-filters/{id}'));
        $this->assertSame('Delete Saved Filter', $this->title('DELETE', 'api/v1/saved-filters/{id}'));
    }

    public function test_a_singular_shaped_get_collection_titles_show_not_list(): void
    {
        $this->assertSame('Show Budget', $this->title('GET', 'api/v1/beam/commerce/budget'));
    }

    public function test_a_nameless_item_action_segment_titles_off_the_trailing_static_segment(): void
    {
        $this->assertSame('Execute Tenant Sync', $this->title('POST', 'api/v1/tenant-syncs/{sync}/execute'));
    }

    // ── precedence ────────────────────────────────────────────────────────

    public function test_an_existing_title_always_wins_the_strategy_never_overwrites(): void
    {
        $endpoint = $this->endpoint('GET', 'api/v1/saved-filters', 'saved_filters.index');
        $endpoint->metadata->title = 'Browse your saved filters';

        $strategy = new RouteTitleStrategy(new DocumentationConfig([]));

        $this->assertNull($strategy($endpoint));
    }
}
