<?php

namespace Splicewire\Beam\Tests\Install;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Splicewire\Beam\Console\ConvergencePreflightCommand;
use Splicewire\Beam\Tests\TestCase;

/**
 * `splicewire:beam:convergence-preflight` — beam-facade ticket 146. The read-only entry point to the
 * install's convergence phase.
 *
 * The property under test is **reachability and honesty**, not rehearsal correctness: the rehearsal
 * itself is `MigrationRehearsal`'s and is covered by ticket 182's tests, and a second set of assertions
 * about what a guard predicts would be the duplicate implementation ticket 109 already refused. What is
 * this command's own is that it can be asked at all without publishing, that it reaches the unpublished
 * stub population no migrator-tied instrument can see, that it never suppresses what it could not
 * rehearse, and that it does not gate by default.
 */
class ConvergencePreflightCommandTest extends TestCase
{
    public function test_the_command_is_registered_so_the_question_can_be_asked_without_publishing(): void
    {
        $this->assertArrayHasKey(
            'splicewire:beam:convergence-preflight',
            $this->app->make(Kernel::class)->all(),
        );
    }

    /**
     * The whole ticket in one assertion: it runs to completion, exits 0, and says out loud that it wrote
     * nothing. Before this command the only read-only invocation was calling the class from `tinker`.
     */
    public function test_it_runs_read_only_and_says_so(): void
    {
        $this->artisan('splicewire:beam:convergence-preflight')
            ->expectsOutputToContain('read-only — nothing has been written')
            ->assertExitCode(0);
    }

    /**
     * Both populations are rendered by default, and the second one is the reason this is a command rather
     * than a flag: `MigrationFiles::pathsFor()` cannot see an unpublished `.php.stub` by construction, so
     * ticket 108's evidence base was unreachable by any instrument in the estate.
     */
    public function test_it_reports_both_populations_by_default(): void
    {
        $this->artisan('splicewire:beam:convergence-preflight')
            ->expectsOutputToContain('Pending migrations')
            ->expectsOutputToContain('Unpublished package stubs')
            ->assertExitCode(0);
    }

    public function test_pending_only_suppresses_the_stub_population(): void
    {
        $this->artisan('splicewire:beam:convergence-preflight', ['--pending-only' => true])
            ->expectsOutputToContain('Pending migrations')
            ->doesntExpectOutputToContain('Unpublished package stubs')
            ->assertExitCode(0);
    }

    public function test_stubs_only_suppresses_the_pending_population(): void
    {
        $this->artisan('splicewire:beam:convergence-preflight', ['--stubs-only' => true])
            ->expectsOutputToContain('Unpublished package stubs')
            ->doesntExpectOutputToContain('Pending migrations')
            ->assertExitCode(0);
    }

    /**
     * The JSON surface carries the conflict's parts rather than a rendered string, because the consumer
     * of `--json` is a tool and a `detail` sentence is not a field. `read_only` is asserted as data for
     * the same reason the human output says it in prose.
     */
    public function test_json_output_is_well_formed_and_declares_both_populations(): void
    {
        // Artisan::call() rather than $this->artisan(): the pending-command assertion runner does not
        // populate the output buffer, so reading it there yields an empty string and the failure reads
        // like malformed JSON rather than like a test that never captured anything.
        $exit = Artisan::call('splicewire:beam:convergence-preflight', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded, "command did not emit valid JSON:\n".$output);
        $this->assertSame('convergence-preflight', $decoded['command']);
        $this->assertTrue($decoded['read_only']);
        $this->assertArrayHasKey('pending', $decoded);
        $this->assertArrayHasKey('unpublished_stubs', $decoded);
    }

    /**
     * ⚠️ The default exit code is 0 **even when the rehearsal finds conflicts**. Every answer here is a
     * fact about the host — does this table exist, what shape is it in — which
     * `gate-or-advisory.convention.md` makes an advisory finding and never a fatal; a command whose exit
     * code moves with the database it happened to reach is that convention's forbidden case. The
     * opt-in flag exists so enforcement belongs to a caller who knows what they pointed it at.
     */
    public function test_it_does_not_gate_by_default_and_the_opt_in_flag_exists(): void
    {
        $this->artisan('splicewire:beam:convergence-preflight')->assertExitCode(0);

        $definition = (new ConvergencePreflightCommand)->getDefinition();

        $this->assertTrue(
            $definition->hasOption('fail-on-conflict'),
            'the opt-in gate flag must exist — without it a pipeline has to parse output to get an answer',
        );
    }
}
