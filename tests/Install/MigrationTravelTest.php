<?php

namespace Splicewire\Beam\Tests\Install;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Splicewire\Beam\Install\MigrationTravel;
use Splicewire\Beam\Tests\TestCase;

/**
 * `--travel=` (beam-facade ticket 117): the installer's lever over where a newly published block lands.
 *
 * Fixtures are real files on disk, for the same reason {@see TableOwnershipTest} uses them — the whole
 * mechanism is a `glob` and a `rename`, and mocking the filesystem would test the mock.
 */
class MigrationTravelTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/beam-migration-travel-'.getmypid();
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function write(string $name): string
    {
        $path = $this->root.'/'.$name;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "<?php // {$name}");

        return $path;
    }

    /** @return list<string> */
    private function names(): array
    {
        $names = array_map(
            fn (string $f) => ltrim(str_replace($this->root, '', $f), '/'),
            array_keys(MigrationTravel::snapshot($this->root)),
        );
        sort($names);

        return $names;
    }

    public function test_it_accepts_a_signed_relative_expression_and_normalizes_it(): void
    {
        $this->assertSame('-1 year', MigrationTravel::parse('-1 year')->expression);
        $this->assertSame('+2 day', MigrationTravel::parse('+2 days')->expression);
        $this->assertSame('-6 month', MigrationTravel::parse('  -6  Months ')->expression);
    }

    public function test_an_absent_or_empty_option_means_no_travel(): void
    {
        $this->assertNull(MigrationTravel::parse(null));
        $this->assertNull(MigrationTravel::parse(''));
    }

    public function test_an_absolute_date_is_refused_and_the_message_says_why(): void
    {
        // Not a validation nicety: an anchor is the `0001_01_01_*` publish band ticket 22 rejected, and
        // it would also collapse the ordering the install manifest just produced.
        foreach (['2026_01_01', '2026-01-01', 'yesterday', '1 year', '-1 fortnight'] as $bad) {
            try {
                MigrationTravel::parse($bad);
                $this->fail("--travel={$bad} should have been refused.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('is not a relative shift', $e->getMessage());
            }
        }
    }

    public function test_it_shifts_only_what_was_published_after_the_snapshot(): void
    {
        $this->write('2024_01_01_000000_create_host_own_table.php');
        $before = MigrationTravel::snapshot($this->root);

        $this->write('2026_08_26_120000_create_beam_particles_table.php');
        $this->write('2026_08_26_120001_create_beam_versions_table.php');

        $result = MigrationTravel::parse('-1 year')->shift($this->root, $before);

        $this->assertSame([], $result['blocked']);
        $this->assertCount(2, $result['moved']);
        $this->assertSame([
            '2024_01_01_000000_create_host_own_table.php',   // pre-existing: untouched
            '2025_08_26_120000_create_beam_particles_table.php',
            '2025_08_26_120001_create_beam_versions_table.php',
        ], $this->names());
    }

    public function test_the_runs_internal_order_and_spacing_survive_the_shift(): void
    {
        // Install order IS migration order, so a lever that re-spaced the block would undo the sequencing
        // the manifest just produced — which is the whole reason the value is a shift, never an anchor.
        $before = MigrationTravel::snapshot($this->root);

        foreach (['000000', '000005', '000030'] as $i => $time) {
            $this->write("2026_08_26_{$time}_create_step_{$i}_table.php");
        }

        MigrationTravel::parse('-2 days')->shift($this->root, $before);

        $this->assertSame([
            '2026_08_24_000000_create_step_0_table.php',
            '2026_08_24_000005_create_step_1_table.php',
            '2026_08_24_000030_create_step_2_table.php',
        ], $this->names());
    }

    public function test_it_travels_a_publish_destination_subdirectory_too(): void
    {
        $before = MigrationTravel::snapshot($this->root);
        $this->write('tenant/2026_08_26_120000_create_cost_events_table.php');

        MigrationTravel::parse('-1 year')->shift($this->root, $before);

        $this->assertSame(['tenant/2025_08_26_120000_create_cost_events_table.php'], $this->names());
    }

    public function test_a_collision_refuses_the_whole_pass_rather_than_half_applying_it(): void
    {
        // A half-shifted block is exactly the "moved relative to some files but not others" state the
        // publish-ordering convention's re-stamping incident describes.
        $this->write('2025_08_26_120000_create_beam_particles_table.php');
        $before = MigrationTravel::snapshot($this->root);

        $this->write('2026_08_26_120000_create_beam_particles_table.php');
        $this->write('2026_08_26_120001_create_beam_versions_table.php');

        $result = MigrationTravel::parse('-1 year')->shift($this->root, $before);

        $this->assertSame([], $result['moved']);
        $this->assertSame(['2025_08_26_120000_create_beam_particles_table.php'], $result['blocked']);
        $this->assertSame([
            '2025_08_26_120000_create_beam_particles_table.php',
            '2026_08_26_120000_create_beam_particles_table.php',
            '2026_08_26_120001_create_beam_versions_table.php',
        ], $this->names());
    }

    public function test_it_names_the_already_published_migrations_the_block_sorted_past(): void
    {
        $this->write('2026_01_15_000000_create_host_mid_table.php');     // crossed: between old and new
        $this->write('2020_01_01_000000_create_host_ancient_table.php'); // below the new position
        $before = MigrationTravel::snapshot($this->root);

        $this->write('2026_08_26_120000_create_beam_particles_table.php');

        $travel = MigrationTravel::parse('-1 year');
        $result = $travel->shift($this->root, $before);

        $this->assertSame(
            ['2026_01_15_000000_create_host_mid_table.php'],
            $travel->crossings($before, $result['moved']),
        );
    }

    public function test_a_block_that_lands_in_a_gap_crosses_nothing(): void
    {
        $this->write('2020_01_01_000000_create_host_ancient_table.php');
        $before = MigrationTravel::snapshot($this->root);

        $this->write('2026_08_26_120000_create_beam_particles_table.php');

        $travel = MigrationTravel::parse('-1 month');
        $result = $travel->shift($this->root, $before);

        $this->assertSame([], $travel->crossings($before, $result['moved']));
    }

    public function test_a_calendar_shift_resolves_once_so_a_month_end_overflow_cannot_reorder_the_block(): void
    {
        // PHP's calendar arithmetic overflows — `2026-03-31 -1 month` is `2026-03-03`, February having no
        // 31st — so evaluating the expression PER FILE can invert two files a second apart that straddle a
        // day boundary. The delta is resolved once, against the run's earliest stamp, and applied to all.
        $before = MigrationTravel::snapshot($this->root);
        $this->write('2026_03_31_235959_create_a_table.php');
        $this->write('2026_04_01_000000_create_b_table.php');

        MigrationTravel::parse('-1 month')->shift($this->root, $before);

        $names = $this->names();
        $this->assertCount(2, $names);
        $this->assertLessThan(0, strcmp($names[0], $names[1]), 'the block was reordered by the shift');
        // Exactly one second apart before, exactly one second apart after.
        $this->assertSame('2026_03_03_235959_create_a_table.php', $names[0]);
        $this->assertSame('2026_03_04_000000_create_b_table.php', $names[1]);
    }

    public function test_a_rerun_moves_nothing_which_is_what_keeps_it_out_of_the_ledger_half(): void
    {
        // The moved set is exactly "did not exist before this run", and a file that did not exist cannot
        // have run — so no move can ever orphan a `migrations` row. That boundary is by construction, not
        // by care, and this is the test that says so.
        $before = MigrationTravel::snapshot($this->root);
        $this->write('2026_08_26_120000_create_beam_particles_table.php');

        $travel = MigrationTravel::parse('-1 year');
        $travel->shift($this->root, $before);

        $second = $travel->shift($this->root, MigrationTravel::snapshot($this->root));

        $this->assertSame([], $second['moved']);
        $this->assertSame(['2025_08_26_120000_create_beam_particles_table.php'], $this->names());
    }

    /**
     * The command-level half: a malformed `--travel=` costs a re-read of the flag, never a half-published
     * host. It is parsed before the manifest is even read, so the install exits non-zero having published
     * nothing.
     */
    public function test_the_installer_refuses_a_malformed_travel_before_publishing_anything(): void
    {
        $this->artisan('splicewire:beam:install', [
            '--travel' => '2026_01_01',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('is not a relative shift')
            ->doesntExpectOutputToContain('splicewire:beam:install → splicewire/laravel-beam (core)')
            ->assertExitCode(1);
    }
}
