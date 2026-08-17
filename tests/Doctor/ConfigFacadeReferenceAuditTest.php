<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\ConfigFacadeReferenceAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-facade ticket 19 — the config-template check ticket 05 handed on and ticket 10 §5 rebuilt on a
 * corrected argument.
 *
 * The argument that does NOT justify it: "it fatals loudly." That is an argument *against* an advisory
 * check. The argument that does: **the break is latent until a host publishes**, so the hazard is
 * authored in one repo and detonates in another while the authoring package's suite stays green.
 *
 * Comment-awareness is what makes it usable rather than a nicety — six config files across the estate
 * name `Beam::` and all six are comments.
 */
class ConfigFacadeReferenceAuditTest extends TestCase
{
    /** @return list<array{line: int, class: string}> */
    private function calls(string $source): array
    {
        return (new ConfigFacadeReferenceAudit([]))->callsInSource($source);
    }

    /** The specimen ticket 10 found live in `laravel-beam-threads/config/beam/threads.php`, since removed by 17. */
    public function test_it_flags_a_facade_call_evaluated_at_config_load(): void
    {
        $rows = $this->calls(<<<'PHP'
        <?php

        use Splicewire\Beam\Facades\Beam;

        return [
            'tables' => [
                'threads' => env('BEAM_THREADS_TABLE', Beam::table('threads')),
            ],
        ];
        PHP);

        $this->assertCount(2, $rows);
        $this->assertSame(3, $rows[0]['line']);
        $this->assertSame(7, $rows[1]['line']);
    }

    /** The deleted bridge detonates for a second reason on top of load order: the class is gone. */
    public function test_it_flags_the_deleted_bridge_too(): void
    {
        $rows = $this->calls("<?php\n\nuse Splicewire\\Beam\\Beam;\n\nreturn ['t' => Beam::table('x')];\n");

        $this->assertCount(2, $rows);
        $this->assertSame('Splicewire\Beam\Beam', $rows[0]['class']);
    }

    /**
     * The six-false-positives-on-day-one test. Every config-file mention of `Beam::` in the estate is a
     * comment, several explaining that prefixing is beam core's job, and
     * `laravel-beam/config/beam/core.php:76` carries a `{@see}` pointing at exactly the right class.
     * Flagging those is how an advisory check gets ignored and then deleted.
     */
    public function test_it_does_not_flag_comments_including_a_resolved_see_tag(): void
    {
        $this->assertSame([], $this->calls(<<<'PHP'
        <?php

        return [
            /*
            | Table names, illustrative. The AUTHORITATIVE resolver is Beam::table($name), which is
            | resolved in ONE place ({@see \Splicewire\Beam\Facades\Beam::table()}).
            */
            'tables' => ['particles' => 'beam_particles'],

            // Table-prefix note: prefixing is beam core's job — the models call Beam::table() directly.
            'prefix_note' => true,
        ];
        PHP));
    }

    /**
     * The grammar line, stated so it is not mistaken for a gap: this check's predicate is **execution at
     * config-load time**, so a merely stale docblock tag in a config file is out of scope here. That is a
     * documentation defect, and the stub sibling covers the same staleness where it actually ships.
     */
    public function test_a_stale_doc_tag_alone_is_not_this_checks_business(): void
    {
        $this->assertSame([], $this->calls("<?php\n\n/** @see \\Splicewire\\Beam\\Beam::table() */\nreturn [];\n"));
    }

    public function test_a_clean_scan_passes_and_states_what_it_covered(): void
    {
        $findings = (new ConfigFacadeReferenceAudit([]))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame(ConfigFacadeReferenceAudit::CHECK, $findings[0]->check);
        $this->assertStringContainsString('0 config file(s) across 0 package(s)', $findings[0]->detail);
    }
}
