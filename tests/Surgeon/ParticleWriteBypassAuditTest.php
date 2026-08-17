<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Surgeon\ParticleWriteBypassAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-facade ticket 19 — the audit that supersedes `StaticBridgeAudit`.
 *
 * The load-bearing tests here are the **negative** ones, and that is not a stylistic preference. Ticket
 * 10's census measured the "reached by constructor DI" shape at 9 naive hits and 9 false positives —
 * **100%** — which is ticket 04's `SchemaTargetResolver` rejection made mechanical, and the reason this
 * audit keys on a resolution verb plus a `->write()` chain instead of on a class name. Each negative
 * test below is one of the sites ticket 10 §4 named as must-not-flag acceptance data.
 */
class ParticleWriteBypassAuditTest extends TestCase
{
    /** @return list<array{line: int, kind: string, detail: string}> */
    private function bypasses(string $source): array
    {
        return (new ParticleWriteBypassAudit(new FacadeConformanceScope([])))->bypassesInSource($source);
    }

    public function test_it_flags_app_resolution_followed_by_write(): void
    {
        $rows = $this->bypasses(<<<'PHP'
        <?php

        use Splicewire\Beam\Write\ParticleWriter;

        class Thread
        {
            public function reply(): void
            {
                app(ParticleWriter::class)->write($this, $payload);
            }
        }
        PHP);

        $this->assertCount(1, $rows);
        $this->assertSame(9, $rows[0]['line']);
        $this->assertSame('service-location', $rows[0]['kind']);
    }

    /** `$this->app->make(...)` and `resolve(...)` are the same act wearing different verbs. */
    public function test_it_flags_the_container_method_forms(): void
    {
        $rows = $this->bypasses(<<<'PHP'
        <?php

        use Splicewire\Beam\Write\ParticleWriter;

        class Seeder
        {
            public function run(): void
            {
                $this->app->make(ParticleWriter::class)->write($a, $b);
                resolve(ParticleWriter::class)->write($c, $d);
            }
        }
        PHP);

        $this->assertCount(2, $rows);
    }

    /** A fully-qualified reference resolves the same as an imported short name. */
    public function test_it_flags_a_fully_qualified_reference(): void
    {
        $rows = $this->bypasses("<?php\n\napp(\\Splicewire\\Beam\\Write\\ParticleWriter::class)->write(\$a, \$b);\n");

        $this->assertCount(1, $rows);
    }

    // ---- The must-not-flag acceptance data (ticket 10 §4) --------------------------------

    /**
     * **The 100% false-positive shape.** All 9 of the census's hits were promoted constructor properties
     * — precisely the pattern the facade exists to preserve. A check keyed on the class name is wrong
     * nine times out of nine, and that is a construction constraint rather than an exclusion: this audit
     * never asks "is `ParticleWriter` named here?"
     */
    public function test_it_does_not_flag_constructor_dependency_injection(): void
    {
        $this->assertSame([], $this->bypasses(<<<'PHP'
        <?php

        use Splicewire\Beam\Write\ParticleWriter;

        class ParticleStorageDriver
        {
            public function __construct(protected ParticleWriter $writer) {}

            public function store($model, $payload): void
            {
                $this->writer->write($model, $payload);
            }
        }
        PHP));
    }

    /**
     * The two deliberate direct instantiations ticket 10 named — `ParticleFrameResourceHandler` and
     * `BeamUxEntryBodyController` — pass a specific write gate per call, which is uncollapsible by
     * construction: `Beam::write()` resolves the container-bound writer, and `ParticleWriter` is bound
     * `bind()` precisely so a gate can be rebound per call (ticket 05). The population has grown since
     * ticket 10 counted it, and keying on resolution verbs alone scales with it for free.
     */
    public function test_it_does_not_flag_a_deliberate_direct_instantiation(): void
    {
        $this->assertSame([], $this->bypasses(<<<'PHP'
        <?php

        use Splicewire\Beam\Write\ParticleWriter;

        class BeamUxEntryBodyController
        {
            public function store(): void
            {
                $writer = new ParticleWriter($gate, $this->targets, $this->acceptance, $this->events);
                $writer->write($entry, $payload);
            }
        }
        PHP));
    }

    /**
     * A resolution whose result is passed as an ARGUMENT is ordinary wiring, not a bypass —
     * `new ParticleStorageDriver($app->make(ParticleWriter::class))` in `BeamUxServiceProvider`, and
     * beam's own binding. Requiring the `->write()` chain excludes it structurally rather than by an
     * exception list.
     */
    public function test_it_does_not_flag_a_resolution_passed_as_a_collaborator(): void
    {
        $this->assertSame([], $this->bypasses(<<<'PHP'
        <?php

        use Splicewire\Beam\Write\ParticleWriter;

        $driver = new ParticleStorageDriver($app->make(ParticleWriter::class));
        PHP));
    }

    /** A different class resolved and written through is not this seam. */
    public function test_it_does_not_flag_another_classs_write(): void
    {
        $this->assertSame([], $this->bypasses("<?php\n\napp(RevisionWriter::class)->write(\$a);\n"));
    }

    // ---- The second finding type (06's drift shape) -------------------------------------

    /**
     * The instance took the distinct name `BeamManager` only because PHP forbids one class declaring
     * `table()` as both an instance and a static method (ticket 06 at the compiler). Reaching past the
     * facade to name it is naming an implementation detail whose whole purpose was to stay unnamed —
     * a category that did not exist when ticket 10 was written.
     */
    public function test_it_flags_a_call_site_naming_the_manager_directly(): void
    {
        $rows = $this->bypasses(<<<'PHP'
        <?php

        use Splicewire\Beam\BeamManager;

        class Reporter
        {
            public function __construct(protected BeamManager $beam) {}
        }
        PHP);

        $this->assertCount(2, $rows);
        $this->assertSame('manager-reference', $rows[0]['kind']);
    }

    public function test_a_clean_scan_passes(): void
    {
        $findings = (new ParticleWriteBypassAudit(new FacadeConformanceScope([])))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame(ParticleWriteBypassAudit::CHECK, $findings[0]->check);
    }

    /**
     * Every finding is advisory rather than a deterministic fix: the collapse touches imports and, at 3
     * of ticket 04's 14 authored sites, named arguments (`after:`), and no operation in the estate
     * handles it. Nominating one is the agent's judgment call — the seam `OperationSuggestion` exists to
     * preserve, and the posture `ParticleOperationBypassAudit` already takes.
     */
    public function test_findings_nominate_advisory_operations_only(): void
    {
        $audit = new class(new FacadeConformanceScope([])) extends ParticleWriteBypassAudit
        {
            public function bypasses(): array
            {
                return [['file' => 'src/Models/Thread.php', 'line' => 9, 'kind' => 'service-location', 'detail' => 'x']];
            }
        };

        $pairs = $audit->suggestOperations();

        $this->assertCount(1, $pairs);
        $this->assertSame(DoctorStatus::Warn, $pairs[0]->finding->status);
        $this->assertTrue($pairs[0]->isAdvisory());
        $this->assertFalse($pairs[0]->isFixable());
    }
}
