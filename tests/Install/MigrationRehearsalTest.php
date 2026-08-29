<?php

namespace Splicewire\Beam\Tests\Install;

use Illuminate\Support\Facades\File;
use Splicewire\Beam\Install\MigrationRehearsal;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-facade ticket 187 — the fatal that appeared the moment a SECOND instrument rehearsed the same
 * population in one process.
 *
 * ⚠️ **Without the guard these cases do not FAIL, they kill the PHP process**, and a killed process
 * is the estate's most expensive signature: no `Tests:` summary, no findings, and — measured at
 * `~/Herd/splicewire-app` — `splicewire:beam:doctor` exiting 255 having reported nothing at all. That is
 * why this file asserts on a SKIP REASON rather than on absence of an exception: `Cannot redeclare class`
 * is a fatal error, so there is no exception to assert on and `expectException` would never fire.
 */
class MigrationRehearsalTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/beam-rehearsal-test-'.getmypid().'-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function migration(string $name, string $body): string
    {
        $path = $this->dir.'/'.$name;

        File::put($path, "<?php\n\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Rushing\\SchemaConvergence\\ConvergentTable;\n\n".$body);

        return $path;
    }

    /** The estate's ordinary spelling: anonymous, so there is no name to collide. */
    private function anonymous(string $name, string $table): string
    {
        return $this->migration($name, <<<PHP
        return new class extends Migration
        {
            public function up(): void
            {
                ConvergentTable::named('{$table}')->define(fn (Blueprint \$t) => \$t->uuid('id'))->matches();
            }
        };
        PHP);
    }

    /**
     * The spelling `splicewire/laravel-beam-tenancy` actually ships — one of exactly two named convergent
     * stubs installed at the flagship, and the one that took the doctor run down.
     */
    private function named(string $file, string $class, string $table): string
    {
        return $this->migration($file, <<<PHP
        class {$class} extends Migration
        {
            public function up(): void
            {
                ConvergentTable::named('{$table}')->define(fn (Blueprint \$t) => \$t->uuid('id'))->matches();
            }
        }

        return new {$class};
        PHP);
    }

    public function test_an_anonymous_migration_rehearses_as_many_times_as_it_is_asked(): void
    {
        // The control. Two instruments rehearsing the same anonymous body is fine and must stay fine —
        // a guard that skipped these would silently halve every second audit's coverage.
        $file = $this->anonymous('anon.php.stub', 'beam_rehearsal_anon');

        $first = MigrationRehearsal::of('anon', $file);
        $second = MigrationRehearsal::of('anon', $file);

        $this->assertTrue($first->wasRehearsed());
        $this->assertTrue($second->wasRehearsed(), 'the anonymous case must not be caught by the redeclare guard');
    }

    public function test_a_named_migration_class_is_never_included_at_all(): void
    {
        // ⚠️ Delete the guard and this does not fail — it ENDS the process on `Cannot redeclare class`,
        // which no `catch (Throwable)` can intercept. Refused on the FIRST call, not the second: the
        // include is what poisons the process, and the victim is usually `migrate` a few lines later,
        // which is nowhere near this class.
        $file = $this->named('named.php.stub', 'BeamRehearsalNamedFixture', 'beam_rehearsal_named');

        $first = MigrationRehearsal::of('named', $file);

        $this->assertFalse($first->wasRehearsed());
        $this->assertStringContainsString('BeamRehearsalNamedFixture', (string) $first->skipped);
        $this->assertStringContainsString('Cannot redeclare class', (string) $first->skipped);
        $this->assertFalse(
            class_exists('BeamRehearsalNamedFixture', false),
            'the guard must refuse BEFORE the include — a skip that already declared the class is the defect',
        );
    }

    public function test_rehearsing_a_named_migration_twice_stays_a_skip_rather_than_a_fatal(): void
    {
        // The beam-facade 187 victim: a second instrument over the same population in one process.
        $file = $this->named('twice.php.stub', 'BeamRehearsalTwiceFixture', 'beam_rehearsal_twice');

        $this->assertFalse(MigrationRehearsal::of('twice', $file)->wasRehearsed());
        $this->assertFalse(MigrationRehearsal::of('twice', $file)->wasRehearsed());
    }

    public function test_the_guard_reads_the_declaration_not_whether_the_class_happens_to_be_loaded(): void
    {
        // An earlier, narrower version asked `class_exists(..., false)` and so refused only the SECOND
        // rehearsal. That version could not have helped the beam-docs-satellite 25 victim, where the
        // second loader is the migrator and the first include is already fatal to it.
        $file = $this->named('fresh.php.stub', 'BeamRehearsalNeverLoadedFixture', 'beam_rehearsal_fresh');

        $this->assertFalse(class_exists('BeamRehearsalNeverLoadedFixture', false));
        $this->assertFalse(MigrationRehearsal::of('fresh', $file)->wasRehearsed());
        $this->assertFalse(class_exists('BeamRehearsalNeverLoadedFixture', false));
    }
}
