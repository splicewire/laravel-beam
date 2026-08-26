<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\UnrehearsableStubAudit;
use Splicewire\Beam\Install\RehearsalSafety;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-facade ticket 109 — the coverage the install-time preflight cannot give, made readable without
 * running an install.
 *
 * The audit is not a defect detector. Every body it names is legitimate: ticket 27 ruled foreign keys
 * and primary keys out of the converge path deliberately, and each of these stubs carries its own
 * hand-written idempotency guard. What it reports is how much of a host's convergent population the
 * preflight will decline to speak about, which was previously discoverable only by an operator reading
 * `?` lines mid-`splicewire:beam:install`.
 */
class UnrehearsableStubAuditTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/beam-unrehearsable-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    private function write(string $name, string $body): void
    {
        file_put_contents($this->dir.'/'.$name, "<?php\n".$body);
    }

    private function audit(): array
    {
        return (new UnrehearsableStubAudit([$this->dir]))->run();
    }

    private function pure(): string
    {
        return <<<'PHP'
        return new class extends Migration {
            public function up(): void {
                ConvergentTable::for('widgets')->create(fn ($t) => $t->uuid('id'));
            }
            public function down(): void { Schema::dropIfExists('widgets'); }
        };
        PHP;
    }

    public function test_a_host_with_no_convergent_migration_passes_and_says_what_it_scanned(): void
    {
        $this->write('2026_01_01_000000_create_plain_table.php', 'return new class {};');

        $findings = $this->audit();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('No convergent migration is published', $findings[0]->detail);
    }

    public function test_a_pure_convergent_population_passes_with_its_count(): void
    {
        $this->write('2026_01_01_000000_create_widgets_table.php', $this->pure());
        $this->write('2026_01_02_000000_create_gadgets_table.php', $this->pure());

        $findings = $this->audit();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('All 2 convergent migration(s)', $findings[0]->detail);
    }

    public function test_it_counts_the_unrehearsable_and_names_every_one(): void
    {
        $this->write('2026_01_01_000000_create_widgets_table.php', $this->pure());
        $this->write('2026_01_02_000000_create_particles_table.php', <<<'PHP'
        return new class extends Migration {
            public function up(): void {
                ConvergentTable::for('particles')->create(fn ($t) => $t->uuid('id'));
                DB::statement('CREATE UNIQUE INDEX ... WHERE deleted_at IS NULL');
            }
        };
        PHP);

        $findings = $this->audit();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('1 of 2 convergent migration(s)', $findings[0]->detail);
        $this->assertStringContainsString('(50%)', $findings[0]->detail);

        // Every unrehearsable file is NAMED, not just counted — a number with no rows is a number a
        // session has to go and re-derive, which is the defect this audit exists to end.
        $this->assertCount(2, $findings);
        $this->assertStringContainsString('2026_01_02_000000_create_particles_table', $findings[1]->detail);
        $this->assertStringContainsString('a raw database statement', $findings[1]->detail);
    }

    public function test_a_write_confined_to_down_does_not_make_a_body_unrehearsable(): void
    {
        // `down()` is excised on purpose: `Schema::dropIfExists()` is exactly what belongs there and
        // never runs from a rehearsal. A whole-file read would report every well-formed stub as unsafe.
        $this->write('2026_01_01_000000_create_widgets_table.php', $this->pure());

        $findings = $this->audit();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_the_predicate_is_shared_with_the_preflight_and_errs_safe(): void
    {
        // One predicate, two callers. A second copy of this heuristic is what ticket 109 refused.
        $this->assertNull(RehearsalSafety::reasonFor("ConvergentTable::for('x')->create(fn (\$t) => \$t->uuid('id'));"));
        $this->assertSame(
            'a raw database statement',
            RehearsalSafety::reasonFor("ConvergentTable::for('x'); DB::statement('CREATE INDEX');"),
        );
        $this->assertSame(
            'a Schema write outside a convergent guard',
            RehearsalSafety::reasonFor("ConvergentTable::for('x'); Schema::table('x', fn (\$t) => \$t->foreign('parent_id'));"),
        );
        $this->assertSame(
            'a row write',
            RehearsalSafety::reasonFor("ConvergentTable::for('x'); DB::table('x')->insertOrIgnore([]);"),
        );

        // Non-convergent bodies are out of the population entirely — the audit's subject is the
        // preflight's blindness, and the preflight never looks at a migration with no guard in it.
        $this->assertFalse(RehearsalSafety::isConvergent("Schema::create('x', fn (\$t) => \$t->id());"));
    }
}
