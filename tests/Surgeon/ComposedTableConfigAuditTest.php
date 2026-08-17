<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Surgeon\ComposedTableConfigAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-facade ticket 19 — the check over `Beam::tableFor()`, the estate's ONE unambiguous composed
 * accessor (ticket 02's census, licensed by ticket 04 at 12 authored sites across 3 repos).
 */
class ComposedTableConfigAuditTest extends TestCase
{
    /** @return list<array{line: int, verb: string, key: string, stem: string}> */
    private function compositions(string $source): array
    {
        return (new ComposedTableConfigAudit(new FacadeConformanceScope([])))->compositionsInSource($source);
    }

    public function test_it_flags_the_longhand_composition(): void
    {
        $rows = $this->compositions(<<<'PHP'
        <?php

        use Splicewire\Beam\Facades\Beam;

        class Thread
        {
            public function getTable(): string
            {
                return config('beam.threads.tables.threads', Beam::table('threads'));
            }
        }
        PHP);

        $this->assertSame(
            [['line' => 9, 'verb' => 'config', 'key' => 'beam.threads.tables.threads', 'stem' => 'threads']],
            $rows,
        );
    }

    /** Nested inside another call — the shape appears as a `->on(...)` argument in the threads stubs. */
    public function test_it_flags_a_nested_composition(): void
    {
        $rows = $this->compositions(<<<'PHP'
        <?php

        use Splicewire\Beam\Facades\Beam;

        $table->foreignUuid('thread_id')->constrained()
            ->on(config('beam.threads.tables.threads', Beam::table('threads')));
        PHP);

        $this->assertCount(1, $rows);
    }

    /**
     * Ticket 10 left open whether the `env()` clothing belongs here or to the config-file check. It
     * belongs here: this audit keys on the **shape** — a lookup whose fallback is a prefixed table name —
     * while its sibling keys on **where**. A composed `env()` default in a package config file is
     * legitimately both, flagged twice for two different reasons.
     */
    public function test_env_wearing_the_same_shape_is_in_this_audits_grammar(): void
    {
        $rows = $this->compositions("<?php\n\nuse Splicewire\\Beam\\Facades\\Beam;\n\n\$t = env('BEAM_THREADS_TABLE', Beam::table('threads'));\n");

        $this->assertCount(1, $rows);
        $this->assertSame('env', $rows[0]['verb']);
    }

    // ---- The must-not-flag acceptance data (ticket 10 §7) --------------------------------

    /**
     * `laravel-beam/tests/Facade/BeamFacadeTest.php:72,77,81` assert on already-conformant
     * `Beam::tableFor(...)` calls — which any loose "`Beam::table` near `config`" rule would catch
     * despite their being the fix. Excluded twice over: by the `tests/` jurisdiction rule, and
     * structurally, since a `tableFor()` call is not a `config()` whose default is `Beam::table()`.
     */
    public function test_it_does_not_flag_an_already_conformant_table_for_call(): void
    {
        $this->assertSame([], $this->compositions("<?php\n\nuse Splicewire\\Beam\\Facades\\Beam;\n\n\$t = Beam::tableFor('beam.threads.tables.threads', 'threads');\n"));
    }

    /** A bare seam call is the seam working, not a composition. */
    public function test_it_does_not_flag_a_bare_table_call(): void
    {
        $this->assertSame([], $this->compositions("<?php\n\nuse Splicewire\\Beam\\Facades\\Beam;\n\n\$t = Beam::table('threads');\n"));
    }

    /** A `config()` with a plain default has nothing to collapse. */
    public function test_it_does_not_flag_a_config_call_with_an_ordinary_default(): void
    {
        $this->assertSame([], $this->compositions("<?php\n\n\$t = config('beam.threads.tables.threads', 'beam_threads');\n"));
    }

    /**
     * `BeamManager.php:62`'s docblock spells this exact shape out to document what `tableFor()` replaces
     * — shape 3's canonical form sitting inside shape 3's fix. Excluded by the owning-package rule, and
     * again structurally: a docblock is not an expression.
     */
    public function test_it_does_not_flag_the_shape_written_in_a_docblock(): void
    {
        $this->assertSame([], $this->compositions(<<<'PHP'
        <?php

        /**
         * The collapsed form of `config($configKey, Beam::table($stem))` (beam-facade ticket 04).
         */
        class BeamManager {}
        PHP));
    }

    public function test_a_clean_scan_passes(): void
    {
        $findings = (new ComposedTableConfigAudit(new FacadeConformanceScope([])))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame(ComposedTableConfigAudit::CHECK, $findings[0]->check);
    }
}
