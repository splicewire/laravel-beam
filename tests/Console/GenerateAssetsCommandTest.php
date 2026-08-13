<?php

namespace Splicewire\Beam\Tests\Console;

use Illuminate\Support\Facades\Artisan;
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
}
