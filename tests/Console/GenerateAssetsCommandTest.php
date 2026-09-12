<?php

namespace Splicewire\Beam\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-doctrine-followups #13 — the umbrella generator's skip must be REPORTABLE. An unregistered
 * generator is rightly a skip, never a failure (a satellite without schemastud still runs) — but that
 * made silence ambiguous: "legitimately absent" and "ran clean" looked identical from outside. `--json`
 * emits the `{ran, skipped, failed}` summary that disambiguates them.
 */
class GenerateAssetsCommandTest extends TestCase
{
    public function test_json_reports_a_skipped_generator_distinct_from_a_ran_one(): void
    {
        // The testbench host registers the client generator but neither typescript:transform nor
        // schemas:generate — exactly the satellite-without-schemastud shape the skip exists for.
        config()->set('beam.client.out_dir', sys_get_temp_dir().'/beam-assets-'.uniqid());
        config()->set('beam.client.assets.generators', [
            'typescript:transform',
            'splicewire:beam:generate:client',
        ]);

        $exit = Artisan::call('splicewire:beam:generate:assets', ['--json' => true]);
        $summary = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);

        $this->assertIsArray($summary);
        $this->assertSame(['splicewire:beam:generate:client'], $summary['ran']);
        $this->assertSame(['typescript:transform'], $summary['skipped']);
        $this->assertSame([], $summary['failed']);
    }

    public function test_the_human_path_still_warns_and_succeeds_on_a_skip(): void
    {
        config()->set('beam.client.out_dir', sys_get_temp_dir().'/beam-assets-'.uniqid());
        config()->set('beam.client.assets.generators', ['typescript:transform']);

        $this->artisan('splicewire:beam:generate:assets')
            ->expectsOutputToContain("Skipping 'typescript:transform'")
            ->assertSuccessful();
    }

    #[DataProvider('failureModes')]
    public function test_a_failed_generator_is_reported_and_dependent_generators_do_not_run(bool $json, bool $throws): void
    {
        $executed = [];
        Artisan::command('architecture:before', function () use (&$executed): int {
            $executed[] = 'before';
            $this->line('before output');

            return 0;
        });
        Artisan::command('architecture:failure', function () use (&$executed, $throws): int {
            $executed[] = 'failure';
            if ($throws) {
                throw new RuntimeException('Invalid prerequisite');
            }

            return 1;
        });
        Artisan::command('architecture:dependent', function () use (&$executed): int {
            $executed[] = 'dependent';
            $this->line('dependent output');

            return 0;
        });
        config()->set('beam.client.assets.generators', [
            'architecture:before',
            'architecture:missing',
            'architecture:failure',
            'architecture:dependent',
        ]);

        $exit = Artisan::call('splicewire:beam:generate:assets', ['--json' => $json]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertSame(['before', 'failure'], $executed);
        if ($json) {
            $this->assertSame([
                'ran' => ['architecture:before'],
                'skipped' => ['architecture:missing', 'architecture:dependent'],
                'failed' => ['architecture:failure'],
            ], json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR));
        } else {
            $this->assertStringContainsString('architecture:failure', $output);
            $this->assertStringContainsString("Skipping 'architecture:dependent' — an earlier generator failed.", $output);
            $this->assertStringContainsString('One or more generators failed', $output);
            $this->assertStringNotContainsString('All contract artifacts regenerated.', $output);
            $this->assertStringNotContainsString('dependent output', $output);
            if ($throws) {
                $this->assertStringContainsString('Invalid prerequisite', $output);
            }
        }
    }

    public static function failureModes(): array
    {
        return [
            'JSON returned failure' => [true, false],
            'JSON thrown failure' => [true, true],
            'human returned failure' => [false, false],
            'human thrown failure' => [false, true],
        ];
    }
}
