<?php

namespace Splicewire\Beam\Tests\Surface;

use RuntimeException;
use Splicewire\Beam\Surface\ApiGroup;
use Splicewire\Beam\Surface\GroupRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * The resolution chain (api-surface-coherence ticket 17). Each rung is asserted to WIN over the ones
 * below it, because the whole point of the ticket is that a declaration outranks a guess — the two
 * strategies this replaced both guessed, and one of them guessed authoritatively.
 */
class GroupRegistryTest extends TestCase
{
    protected function registry(): GroupRegistry
    {
        return (new GroupRegistry)->registerGroups(
            new ApiGroup(key: 'knowledge', name: 'Knowledge'),
            new ApiGroup(key: 'fragments', name: 'Fragments', parent: 'knowledge'),
            new ApiGroup(key: 'media', name: 'Media', parent: 'knowledge'),
            new ApiGroup(key: 'knowledge-graph', name: 'Knowledge Graph', navLabel: 'Graph', parent: 'knowledge'),
        );
    }

    public function test_a_resource_assignment_outranks_the_uri_globs(): void
    {
        $registry = $this->registry()
            ->assign('media', 'media')
            ->matchUri('api/v1/fragments/*', 'fragments');

        // Ticket 01's forcing case: the URL starts with `fragments`, the route belongs to `media`.
        $group = $registry->resolveRoute('media', 'api/v1/fragments/{fragment}/media');

        $this->assertSame('Media', $group->name);
    }

    public function test_the_globs_catch_a_route_that_declares_no_resource(): void
    {
        $registry = $this->registry()->matchUri(['api/v1/fragments', 'api/v1/fragments/*'], 'fragments');

        $this->assertSame('Fragments', $registry->resolveRoute(null, 'api/v1/fragments/9')->name);
    }

    public function test_an_unmatched_route_falls_through_to_an_undeclared_guess(): void
    {
        $registry = $this->registry();

        $group = $registry->resolveRoute(null, 'api/v1/whatever-this-is/9');

        // Headline, not studly — a studly guess can never match a hand-written multi-word name, which is
        // how one resource once split into `ContextScopes` beside `Context Scopes`.
        $this->assertSame('Whatever This Is', $group->name);
        $this->assertFalse($registry->isDeclared($group), 'a guess must be distinguishable from a declaration');
    }

    public function test_the_guess_strips_version_and_tier_noise(): void
    {
        $this->assertSame('Circuits', $this->registry()->guessFromUri('api/v1/splice/circuits/{id}/op/run')->name);
    }

    public function test_a_nav_label_resolves_to_the_same_group_as_the_canonical_name(): void
    {
        $registry = $this->registry();

        // One taxonomy, two renderings: a package declaring the short menu word "Graph" and the
        // reference's tag "Knowledge Graph" are the SAME group, not two.
        $this->assertSame('knowledge-graph', $registry->resolve('Graph')?->key);
        $this->assertSame('knowledge-graph', $registry->resolve('Knowledge Graph')?->key);
        $this->assertSame('knowledge-graph', $registry->resolve('knowledge-graph')?->key);
    }

    public function test_an_unresolvable_token_is_a_miss_not_an_error(): void
    {
        // A package default the host has not adopted falls through to the next rung.
        $this->assertNull($this->registry()->resolve('Settings'));
    }

    public function test_ancestry_walks_to_the_root(): void
    {
        $registry = $this->registry();

        $this->assertSame(
            ['fragments', 'knowledge'],
            array_map(fn (ApiGroup $g): string => $g->key, $registry->ancestry('fragments')),
        );
        $this->assertSame('knowledge', $registry->root('fragments')?->key);
    }

    public function test_validate_rejects_a_parent_that_was_never_registered(): void
    {
        $registry = (new GroupRegistry)->registerGroups(new ApiGroup(key: 'orphan', name: 'Orphan', parent: 'nowhere'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('parent [nowhere]');

        $registry->validate();
    }

    public function test_validate_rejects_a_cycle(): void
    {
        $registry = (new GroupRegistry)->registerGroups(
            new ApiGroup(key: 'a', name: 'A', parent: 'b'),
            new ApiGroup(key: 'b', name: 'B', parent: 'a'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('own ancestor');

        $registry->validate();
    }

    public function test_validate_rejects_an_assignment_or_glob_pointing_at_nothing(): void
    {
        $registry = $this->registry()->assign('media', 'not-a-group');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unregistered group [not-a-group]');

        $registry->validate();
    }

    public function test_registering_the_same_key_twice_replaces_rather_than_duplicates(): void
    {
        $registry = $this->registry()->register(new ApiGroup(key: 'media', name: 'Attachments', parent: 'knowledge'));

        $this->assertCount(4, $registry->all());
        $this->assertSame('Attachments', $registry->get('media')?->name);
    }
}
