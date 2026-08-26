<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-doctrine-convergence ticket 06 — the committed artifact, which is what makes the number a
 * burn-down rather than a wall.
 *
 * A number that lives only in command output is a number nobody is accountable to. Committed, it shows up as
 * a diff in review and can only move one way.
 */
class UndeclaredSurfaceArtifactTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir().'/beam-undeclared-'.uniqid().'/artifact.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));

        parent::tearDown();
    }

    private function artifact(): array
    {
        return json_decode((string) file_get_contents($this->path), true);
    }

    public function test_the_command_is_registered(): void
    {
        $this->assertArrayHasKey(
            'splicewire:beam:undeclared-surface',
            $this->app[Kernel::class]->all(),
        );
    }

    public function test_it_writes_the_artifact_with_a_count_and_the_surfaces(): void
    {
        Route::get('api/v1/orphans', [UndeclaredFixtureController::class, 'plain']);

        $this->artisan('splicewire:beam:undeclared-surface', ['--path' => $this->path])->assertSuccessful();

        $artifact = $this->artifact();

        $this->assertSame('particle.undeclared-surface', $artifact['check']);
        $this->assertSame(count($artifact['surfaces']), $artifact['count']);
        $this->assertContains('api/v1/orphans', array_column($artifact['surfaces'], 'uri'));
    }

    public function test_writing_twice_with_no_change_produces_a_byte_identical_artifact(): void
    {
        Route::get('api/v1/zebra', [UndeclaredFixtureController::class, 'plain']);
        Route::get('api/v1/alpha', [UndeclaredFixtureController::class, 'plain']);

        $this->artisan('splicewire:beam:undeclared-surface', ['--path' => $this->path])->assertSuccessful();
        $first = (string) file_get_contents($this->path);

        $this->artisan('splicewire:beam:undeclared-surface', ['--path' => $this->path])->assertSuccessful();

        // Byte-identical, or the artifact churns in review and its ratchet contract becomes unreadable.
        $this->assertSame($first, (string) file_get_contents($this->path));
    }

    public function test_check_passes_when_the_count_is_unchanged(): void
    {
        Route::get('api/v1/orphans', [UndeclaredFixtureController::class, 'plain']);

        $this->artisan('splicewire:beam:undeclared-surface', ['--path' => $this->path])->assertSuccessful();

        $this->artisan('splicewire:beam:undeclared-surface', ['--check' => true, '--path' => $this->path])
            ->assertSuccessful();
    }

    public function test_check_fails_when_the_count_increases_and_names_the_new_surface(): void
    {
        $this->artisan('splicewire:beam:undeclared-surface', ['--path' => $this->path])->assertSuccessful();

        // New drift arrives after the baseline was committed.
        Route::get('api/v1/newly-undeclared', [UndeclaredFixtureController::class, 'plain']);

        $this->artisan('splicewire:beam:undeclared-surface', ['--check' => true, '--path' => $this->path])
            ->expectsOutputToContain('INCREASED')
            ->expectsOutputToContain('api/v1/newly-undeclared')
            ->assertFailed();
    }

    public function test_check_passes_on_a_decrease_rather_than_demanding_a_rewrite(): void
    {
        // Baseline recorded WITH drift present...
        Route::get('api/v1/temporary', [UndeclaredFixtureController::class, 'plain']);
        $this->artisan('splicewire:beam:undeclared-surface', ['--path' => $this->path])->assertSuccessful();

        $baseline = $this->artifact();
        $this->assertGreaterThan(0, $baseline['count']);

        // ...then hand-drop an entry to stand in for a surface that has since been declared. Failing on any
        // difference would make the ratchet obstruct the improvement it exists to encourage.
        $baseline['surfaces'][] = ['uri' => 'api/v1/since-declared', 'methods' => ['GET']];
        $baseline['count'] = count($baseline['surfaces']);
        file_put_contents($this->path, json_encode($baseline));

        $this->artisan('splicewire:beam:undeclared-surface', ['--check' => true, '--path' => $this->path])
            ->expectsOutputToContain('decreased')
            ->assertSuccessful();
    }

    /**
     * beam-facade ticket 140 — the artifact records COMPOSITION, not a scalar.
     *
     * The scalar is what let two sessions independently misattribute a 356 → 433 move to
     * `api/v1/beam/accounts/*`, when only 15 of the 433 rows mentioned `beam/accounts` and 226 came from
     * `splicewire/tower`. Every one of those facts was derivable from the rows and none of them was
     * recorded, so nobody derived them.
     */
    public function test_the_artifact_records_composition_and_a_stated_baseline_environment(): void
    {
        Route::get('api/v1/orphans', [UndeclaredFixtureController::class, 'plain']);

        $this->artisan('splicewire:beam:undeclared-surface', ['--path' => $this->path])->assertSuccessful();

        $artifact = $this->artifact();

        $this->assertArrayHasKey('by_tier', $artifact['counts']);
        $this->assertArrayHasKey('by_origin', $artifact['counts']);
        $this->assertSame($artifact['count'], array_sum($artifact['counts']['by_tier']));
        $this->assertSame($artifact['count'], array_sum($artifact['counts']['by_origin']));

        // Which environment produced the number, so a disagreement between two machines is readable.
        $this->assertArrayHasKey('environment', $artifact['baseline']);
        $this->assertArrayHasKey('dev_dependencies_installed', $artifact['baseline']);

        // Every row carries the field the buckets are built from.
        foreach ($artifact['surfaces'] as $row) {
            $this->assertArrayHasKey('origin', $row);
        }
    }

    /**
     * The exclusion is stated ON the artifact, with its reason — an exclusion nobody can see is
     * indistinguishable from a check that quietly stopped looking, which is the class of defect this
     * whole ticket is an instance of.
     */
    public function test_the_artifact_states_what_it_excluded_and_why(): void
    {
        $this->artisan('splicewire:beam:undeclared-surface', ['--path' => $this->path])->assertSuccessful();

        $excluded = $this->artifact()['excluded'];

        $this->assertArrayHasKey('count', $excluded['dev_only']);
        $this->assertStringContainsString('require-dev', $excluded['dev_only']['reason']);
        $this->assertSame('IN', $excluded['production_vendor']['policy']);
        $this->assertStringContainsString('119', $excluded['production_vendor']['reason']);
    }

    /**
     * A committed artifact may not carry the author's home directory. The estate makes this sharper than
     * it sounds: the co-dev overlay symlinks family packages, so most locations resolved into
     * `~/Workspaces/php/packages/...` and did not even mention `vendor/`.
     */
    public function test_no_row_carries_an_absolute_machine_path(): void
    {
        Route::get('api/v1/orphans', [UndeclaredFixtureController::class, 'plain']);

        $this->artisan('splicewire:beam:undeclared-surface', ['--path' => $this->path])->assertSuccessful();

        foreach ($this->artifact()['surfaces'] as $row) {
            $this->assertStringStartsNotWith('/', $row['location'], $row['uri'].' carries an absolute path');
        }
    }

    public function test_check_fails_loudly_when_no_artifact_has_been_committed_yet(): void
    {
        $this->artisan('splicewire:beam:undeclared-surface', ['--check' => true, '--path' => $this->path])
            ->expectsOutputToContain('No committed artifact')
            ->assertFailed();
    }
}
