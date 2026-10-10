<?php

namespace Splicewire\Beam\Tests\Data;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Schemastud\Frame\FrameServiceProvider;
use Spatie\LaravelData\Support\DataContainer;
use Splicewire\Beam\Data\GitRepoData;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Models\GitRepo;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * Owner ruling 2026-10-09 18:18Z: the ecosystem DTO wire-name standard is camelCase, greenfield, no aliases. The two
 * laravel-beam READ projections that still published snake keys (HookData, GitRepoData) publish camel, pinned with
 * #[MapName] so no host OUTPUT mapper can float them; the snake columns are mapped explicitly in each `project()`.
 * This class runs on a host with no output mapper; {@see ReadProjectionWireNameSnakeOutputHostTest} on a snake one.
 */
class ReadProjectionWireNameTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('app.key', str_repeat('r', 32));
        $app['config']->set('data.name_mapping_strategy.output', $this->hostOutputMapper());
    }

    protected function hostOutputMapper(): ?string
    {
        return null;
    }

    protected function getPackageProviders($app): array
    {
        return [FrameServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    /**
     * laravel-data's DataContainer is process-static: its DataClassFactory caches each Data class's resolved
     * name mapping from the FIRST app that built it. This suite switches the host's global output mapper
     * (ReadProjectionWireNameSnakeOutputHostTest sets snake), so it must neither inherit classes cached under
     * another mapper nor leave its own behind; otherwise later suites read `next_cursor` for `nextCursor`
     * (the combined-line reds in CompositeStreamedIndex/UnpagedBacking/StreamFilterExecution).
     */
    protected function setUp(): void
    {
        DataContainer::get()->reset();
        parent::setUp();
        (require __DIR__.'/../../database/migrations/shared/create_beam_hooks_table.php.stub')->up();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        DataContainer::get()->reset();
    }

    private function hook(): Hook
    {
        return Hook::create([
            'endpoint' => 'https://receiver.test/hooks',
            'secret' => Hook::mintSecret(),
            'events' => ['wire-names.happened'],
            'subject_type' => 'tenant',
            'subject_id' => 'abc',
            'paused_at' => now(),
            'consecutive_failures' => 2,
        ]);
    }

    public function test_hook_read_projection_publishes_camel_keys(): void
    {
        $out = HookData::project($this->hook())->toArray();

        $this->assertSame(
            ['id', 'endpoint', 'events', 'subjectType', 'subjectId', 'pausedAt', 'disabledAt', 'consecutiveFailures',
                'lastFailureRequestLogId', 'verifiedAt', 'secretPreview', 'deliverable', 'createdAt'],
            array_keys($out),
        );
        $this->assertSame('tenant', $out['subjectType']);
        $this->assertSame('abc', $out['subjectId']);
        $this->assertSame(2, $out['consecutiveFailures']);
        $this->assertNotNull($out['pausedAt']);
        $this->assertFalse($out['deliverable']);
    }

    public function test_the_hooks_particle_index_serves_the_camel_projection(): void
    {
        $this->app->make(ParticleResourceRegistry::class)->registerClass(HookData::class);
        // Integrator ruling 2026-10-10 02:43Z: a policy-bound list needs viewAny AND an
        // authored population boundary. This wire-shape test deliberately exposes all fixture rows.
        $this->app->make(ParticleResourceRegistry::class)->get('hooks')->scope =
            fn (Builder $query): Builder => $query->whereNotNull($query->getModel()->getQualifiedKeyName());
        $this->actingAs((new User)->forceFill(['id' => 1]));
        Gate::before(fn () => true);
        $hook = $this->hook();

        $row = $this->getJson('/frame/resources/hooks')->assertOk()->json('data.0');

        $this->assertSame($hook->id, $row['id']);
        $this->assertArrayHasKey('subjectType', $row);
        $this->assertArrayHasKey('consecutiveFailures', $row);
        $this->assertArrayNotHasKey('subject_type', $row);
        $this->assertArrayNotHasKey('consecutive_failures', $row);
    }

    public function test_git_repo_read_projection_publishes_camel_keys(): void
    {
        $repo = (new GitRepo)->forceFill([
            'id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
            'root_path' => '/srv/repo',
            'branch' => 'main',
            'head_sha' => str_repeat('a', 40),
            'dirty_paths' => ['src/A.php'],
            'untracked_paths' => ['new.txt'],
            'checked_at' => now(),
        ]);

        $out = GitRepoData::project($repo)->toArray();

        $this->assertSame(['id', 'rootPath', 'branch', 'headSha', 'dirtyPaths', 'untrackedPaths', 'checkedAt'], array_keys($out));
        $this->assertSame('/srv/repo', $out['rootPath']);
        $this->assertSame(['src/A.php'], $out['dirtyPaths']);
        $this->assertSame(['new.txt'], $out['untrackedPaths']);
    }
}
