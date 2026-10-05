<?php

namespace Splicewire\Beam\Tests\Testing;

use PHPUnit\Framework\AssertionFailedError;
use Splicewire\Beam\Testing\AssertsHostIaSeam;
use Splicewire\Beam\Tests\TestCase;

/**
 * T7a and T8's rules over synthetic manifests (app-walkthrough APP-01): a nav routeName that names no leaf, a homeless
 * place (per principal, collapsed when nobody seats it), an empty label, a seated place whose list is refused, an
 * omitted place whose list answers, and the closure ratchet's two-way contract.
 */
class AssertsHostIaClosureTest extends TestCase
{
    use AssertsHostIaSeam;

    /** @var array<string, array<string, mixed>|null> by "realm principal" */
    private array $manifests = [];

    /** @var array<string, int> by "realm principal resource" */
    private array $statuses = [];

    /** @var array<string, string> */
    private array $closureRatchet = [];

    protected function setUp(): void
    {
        parent::setUp();

        $leaves = [
            ['routeName' => 'posts.index', 'path' => 'posts', 'mounts' => 'list', 'resource' => 'posts'],
            ['routeName' => 'posts.edit', 'path' => 'posts/:id', 'mounts' => 'edit', 'resource' => 'posts'],
            ['routeName' => 'drafts.index', 'path' => 'drafts', 'mounts' => 'list', 'resource' => 'drafts'],
            ['routeName' => 'audit.index', 'path' => 'audit', 'mounts' => 'list', 'resource' => 'audit'],
        ];
        $resources = [['key' => 'posts', 'nav' => ['label' => 'Posts']], ['key' => 'drafts', 'nav' => ['label' => '']], ['key' => 'audit', 'nav' => ['label' => 'Audit']]];
        $nav = fn (array $items) => ['items' => [['title' => 'Content', 'routeName' => 'content.section', 'children' => $items]]];

        $this->manifests = [
            'tenant owner' => ['routeContext' => $leaves, 'resources' => $resources, 'nav' => $nav([
                ['title' => 'Posts', 'routeName' => 'posts.index'],
                ['title' => 'Drafts', 'routeName' => 'drafts.index'],
                ['title' => 'Ghost', 'routeName' => 'ghost.index'],
            ])],
            'tenant member' => ['routeContext' => $leaves, 'resources' => $resources, 'nav' => $nav([
                ['title' => 'Posts', 'routeName' => 'posts.index'],
            ])],
            'tenant guest' => null,
        ];
        $this->statuses = [
            'tenant owner posts' => 200, 'tenant owner drafts' => 403, 'tenant owner audit' => 200,
            'tenant member posts' => 200, 'tenant member drafts' => 403, 'tenant member audit' => 200,
            'tenant guest posts' => 401, 'tenant guest drafts' => 401, 'tenant guest audit' => 401,
        ];
    }

    protected function hostIaRatchet(): array
    {
        return [];
    }

    protected function hostIaPlays(): array
    {
        return [];
    }

    protected function hostIaPrincipals(): array
    {
        return ['owner', 'member', 'guest'];
    }

    protected function hostIaClosureRealms(): array
    {
        return ['tenant'];
    }

    protected function hostIaManifestAs(string $realm, string $principal): ?array
    {
        return $this->manifests["{$realm} {$principal}"] ?? null;
    }

    protected function hostIaListStatusAs(string $realm, string $principal, string $resource): int
    {
        return $this->statuses["{$realm} {$principal} {$resource}"];
    }

    protected function hostIaClosureRatchet(): array
    {
        return $this->closureRatchet;
    }

    public function test_t7a_names_orphans_homeless_places_and_empty_labels(): void
    {
        $this->assertSame([
            'T7a tenant empty-label leaf drafts.index',
            'T7a tenant member no-home drafts.index',
            'T7a tenant no-home audit.index',
            'T7a tenant owner nav-orphan ghost.index',
        ], $this->sortedKeys($this->hostIaT7()));
    }

    public function test_t8_names_a_refused_seat_and_an_open_omission_per_principal(): void
    {
        $this->assertSame([
            'T8 tenant member omitted-open audit',
            'T8 tenant owner omitted-open audit',
            'T8 tenant owner seat-denied drafts',
        ], $this->sortedKeys($this->hostIaT8()));
    }

    public function test_a_deliberately_stale_closure_entry_fails(): void
    {
        $this->closureRatchet = array_fill_keys([...array_keys($this->hostIaT7()), ...array_keys($this->hostIaT8())], 'APP-16');
        $this->closureRatchet['T7a tenant no-home gone.index'] = 'APP-16: already seated';

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Stale closure entries');

        $this->assertHostIaClosureRatchet();
    }

    public function test_an_exact_closure_ratchet_passes(): void
    {
        $this->closureRatchet = array_fill_keys([...array_keys($this->hostIaT7()), ...array_keys($this->hostIaT8())], 'APP-16');

        $this->assertHostIaClosureRatchet();
    }

    /** @return list<string> */
    private function sortedKeys(array $found): array
    {
        $keys = array_keys($found);
        sort($keys);

        return $keys;
    }
}
