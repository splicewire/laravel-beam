<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Surgeon\CentralPinJustificationAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * particle-doctrine-convergence ticket 12 — the central-pin justification audit.
 *
 * The load-bearing test in here is {@see test_it_finds_a_pin_declared_through_a_class_constant()}: the pin
 * form that does not look like a pin. A `grep` for `protected $connection = 'central'`, and every reviewer
 * who has that string in their head as "what a pin looks like", misses `laravel-beam-ux` entirely — which is
 * the whole argument for this being executable rather than a paragraph in the brief.
 *
 * Disk-scanning tests write their sources into a fresh temp root rather than committing fixture files,
 * because the audit EXCLUDES `tests/` and `fixtures/` paths by design — a committed fixture under
 * `tests/Surgeon/` would be skipped by the very rule those tests exist to prove.
 */
class CentralPinJustificationAuditTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null && is_dir($this->root)) {
            foreach ((array) glob($this->root.'/*.php') as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->root);
        }

        parent::tearDown();
    }

    /** Roots are irrelevant for the source-level tests — each drives `pinsInSource()`, the pure core. */
    private function audit(): CentralPinJustificationAudit
    {
        return new CentralPinJustificationAudit([]);
    }

    /** @return list<array<string, mixed>> */
    private function pins(string $source, string $file = '/app/src/Models/Thing.php'): array
    {
        return $this->audit()->pinsInSource($source, $file);
    }

    /**
     * Materialize `name => source` into a fresh scan root, deliberately named to avoid the audit's own
     * `tests`/`fixtures` path exclusions.
     *
     * @param  array<string, string>  $files
     */
    private function scanning(array $files): CentralPinJustificationAudit
    {
        $this->root = sys_get_temp_dir().'/central-pin-scan-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);

        foreach ($files as $name => $source) {
            file_put_contents($this->root.'/'.$name, $source);
        }

        return new CentralPinJustificationAudit([$this->root]);
    }

    // ── both pin forms are found ────────────────────────────────────────────────────────────────────

    public function test_it_finds_a_pin_declared_as_a_connection_property(): void
    {
        $pins = $this->pins(<<<'PHP'
            <?php
            namespace App\Models;
            class Ledger extends Model
            {
                protected $connection = 'central';
            }
            PHP);

        $this->assertCount(1, $pins);
        $this->assertSame('App\\Models\\Ledger', $pins[0]['class']);
        $this->assertSame(CentralPinJustificationAudit::FORM_PROPERTY, $pins[0]['form']);
        $this->assertSame(5, $pins[0]['line']);
    }

    /**
     * The MIGRATION shape — and the population this audit could not see at all.
     *
     * Since Laravel 9 every migration is `return new class extends Migration`, so every migration pin in
     * the estate lived in an anonymous class. The walker skipped those outright on a null name, which reads
     * as ordinary defensiveness and was not: measured at `~/Herd/splicewire-app`, two migrations carrying
     * BOTH a `$connection` property and a `Schema::connection('central')` call reported ZERO pins, while
     * two seeders in the same directory — ordinary named classes — reported fine. Bringing `database/` into
     * the scan roots is what made the gap visible; it was there before, unreachable.
     *
     * An anonymous class has no FQN, so it is addressed by file and line. For a migration that is the
     * better identity anyway — nobody refers to one by class name.
     */
    public function test_it_finds_a_pin_in_an_anonymous_class_such_as_a_migration(): void
    {
        $pins = $this->pins(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\Schema;
            return new class extends Migration
            {
                protected $connection = 'central';

                public function up(): void
                {
                    Schema::connection('central')->create('things', function ($table) {});
                }
            };
            PHP, '/app/database/migrations/2026_01_01_000000_create_things_table.php');

        $this->assertNotSame([], $pins, 'an anonymous class is still a pin site.');

        $forms = array_column($pins, 'form');
        $this->assertContains(CentralPinJustificationAudit::FORM_PROPERTY, $forms);
        $this->assertSame(['class@anonymous'], array_unique(array_column($pins, 'class')));
        $this->assertSame(
            ['/app/database/migrations/2026_01_01_000000_create_things_table.php'],
            array_unique(array_column($pins, 'file')),
            'the file is the address when there is no class name.'
        );
    }

    /**
     * The laravel-beam-ux shape, reduced: the connection name lives in a class CONSTANT and the pin happens
     * through `Model::on(self::CONST)`. Nothing in this class contains the substring a property search looks
     * for, and the reported line is the CONSTANT — the declaration every use site defers to, and therefore
     * the one place a citation belongs.
     */
    public function test_it_finds_a_pin_declared_through_a_class_constant(): void
    {
        $pins = $this->pins(<<<'PHP'
            <?php
            namespace App\Support;
            use App\Models\Entry;
            class Promoter
            {
                public const CENTRAL_CONNECTION = 'central';

                public function promote(): void
                {
                    Entry::on(self::CENTRAL_CONNECTION)->get();
                }
            }
            PHP);

        $this->assertCount(1, $pins);
        $this->assertSame(CentralPinJustificationAudit::FORM_CONSTANT, $pins[0]['form']);
        $this->assertSame(6, $pins[0]['line'], 'The constant declaration is the pin site, not the call.');
        // Resolved through the file's own `use` imports, so the finding names the model that got pinned.
        $this->assertSame(['App\\Models\\Entry'], $pins[0]['targets']);
    }

    public function test_it_finds_a_pin_made_by_a_bare_connection_call_with_no_declaration_site(): void
    {
        $pins = $this->pins(<<<'PHP'
            <?php
            namespace App\Queries;
            use Illuminate\Support\Facades\DB;
            class MemberQuery
            {
                public function run(): void
                {
                    DB::connection('central')->table('tenant_users')->get();
                }
            }
            PHP);

        $this->assertCount(1, $pins);
        $this->assertSame(CentralPinJustificationAudit::FORM_CALL, $pins[0]['form']);
        $this->assertSame(8, $pins[0]['line']);
    }

    public function test_it_finds_a_pin_made_by_an_instance_set_connection_call(): void
    {
        $pins = $this->pins(<<<'PHP'
            <?php
            namespace App\Support;
            use App\Models\Particle;
            class Writer
            {
                public function write(): void
                {
                    (new Particle)->setConnection('central');
                }
            }
            PHP);

        $this->assertCount(1, $pins);
        $this->assertSame(CentralPinJustificationAudit::FORM_CALL, $pins[0]['form']);
        $this->assertSame(['App\\Models\\Particle'], $pins[0]['targets']);
    }

    public function test_a_pin_on_another_connection_is_not_a_central_pin(): void
    {
        $this->assertSame([], $this->pins(<<<'PHP'
            <?php
            namespace App\Models;
            class Reporting extends Model
            {
                protected $connection = 'analytics';
            }
            PHP));
    }

    /**
     * A bare `'central'` string constant is not self-evidently a pin — this audit's own `self::CENTRAL` is
     * exactly that and pins nothing. The connection CALL is the evidence; the constant is only the address.
     */
    public function test_a_central_string_constant_that_pins_no_connection_is_not_a_pin(): void
    {
        $this->assertSame([], $this->pins(<<<'PHP'
            <?php
            namespace App\Support;
            class ConnectionNames
            {
                public const CENTRAL = 'central';

                public function matches(string $name): bool
                {
                    return $name === self::CENTRAL;
                }
            }
            PHP));
    }

    /**
     * One class that declares a constant AND reaches through it three times has pinned central ONCE.
     * Reporting each use site would turn one decision into a three-item backlog and bury the declaration
     * that actually needs the citation.
     */
    public function test_one_class_pinning_repeatedly_is_one_work_item(): void
    {
        $pins = $this->pins(<<<'PHP'
            <?php
            namespace App\Support;
            use App\Models\Entry;
            class Promoter
            {
                private const CENTRAL_CONNECTION = 'central';

                public function a(): void { Entry::on(self::CENTRAL_CONNECTION)->get(); }
                public function b(): void { Entry::on(self::CENTRAL_CONNECTION)->get(); }
                public function c(): void { Entry::on('central')->get(); }
            }
            PHP);

        $this->assertCount(1, $pins);
        $this->assertSame(6, $pins[0]['line']);
    }

    // ── the citation ────────────────────────────────────────────────────────────────────────────────

    public function test_a_pin_citing_a_floor_category_produces_no_finding(): void
    {
        $audit = $this->scanning(['TenantRecord.php' => <<<'PHP'
            <?php
            namespace CentralPinScan;
            class TenantRecord extends Model
            {
                /** @central-floor tenant-isolation */
                protected $connection = 'central';
            }
            PHP]);

        $pins = $audit->pins();

        $this->assertCount(1, $pins, 'The pin is still censused — a citation justifies it, it does not hide it.');
        $this->assertSame('tenant-isolation', $pins[0]['citation']);
        $this->assertTrue($pins[0]['justified']);
        $this->assertSame([], $audit->unjustified());
        $this->assertSame(DoctorStatus::Pass, $audit->run()[0]->status);
    }

    public function test_a_citation_on_the_declaring_class_docblock_also_counts(): void
    {
        $pins = $this->pins(<<<'PHP'
            <?php
            namespace App\Support;
            use App\Models\Entry;

            /**
             * The registry runtime's own index.
             *
             * @central-floor registry-runtime
             */
            class Registry
            {
                private const CENTRAL_CONNECTION = 'central';

                public function all(): void { Entry::on(self::CENTRAL_CONNECTION)->get(); }
            }
            PHP);

        $this->assertTrue($pins[0]['justified']);
        $this->assertSame('registry-runtime', $pins[0]['citation']);
    }

    public function test_a_pin_with_no_citation_produces_an_advisory_finding_naming_the_model(): void
    {
        $audit = $this->scanning(['Lead.php' => <<<'PHP'
            <?php
            namespace CentralPinScan;
            class Lead extends Model
            {
                protected $connection = 'central';
            }
            PHP]);

        $this->assertFalse($audit->pins()[0]['justified']);

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        // Names the model, its form, and its file:line — so the report is a work-list, not a score.
        $this->assertStringContainsString('Lead', $findings[0]->detail);
        $this->assertStringContainsString(CentralPinJustificationAudit::FORM_PROPERTY, $findings[0]->detail);
        $this->assertStringContainsString('Lead.php:5', $findings[0]->detail);
        $this->assertStringContainsString(CentralPinJustificationAudit::TAG, $findings[0]->detail);
    }

    /**
     * The floor list is CLOSED, so a tag naming something outside it is its own reportable state — inventing
     * a category is exactly how a closed list quietly opens. Collapsing this into "uncited" would hide it.
     */
    public function test_a_pin_citing_a_category_outside_the_closed_floor_list_is_still_reported(): void
    {
        $audit = $this->scanning(['ScaffoldPack.php' => <<<'PHP'
            <?php
            namespace CentralPinScan;
            class ScaffoldPack extends Model
            {
                /** @central-floor convenience */
                protected $connection = 'central';
            }
            PHP]);

        $this->assertSame('convenience', $audit->pins()[0]['citation']);
        $this->assertFalse($audit->pins()[0]['justified']);
        $this->assertNotContains('convenience', CentralPinJustificationAudit::FLOOR_CATEGORIES);
        $this->assertStringContainsString('not one of the closed floor categories', $audit->run()[0]->detail);
    }

    /**
     * Prose is deliberately NOT accepted. Nearly every pin in the estate already carries a docblock saying
     * the model lives centrally, and a restatement of the pin answers "what" where the floor test asks
     * "which category of floor" — only a named category is decidable against a closed list.
     */
    public function test_prose_restating_the_pin_is_not_a_citation(): void
    {
        $pins = $this->pins(<<<'PHP'
            <?php
            namespace App\Models;

            /**
             * The central audit trail — a spatie Activity deliberately pinned to the central
             * connection, where central-scoped subjects live.
             */
            class CentralActivityLog extends Model
            {
                protected $connection = 'central';
            }
            PHP);

        $this->assertCount(1, $pins);
        $this->assertNull($pins[0]['citation']);
    }

    // ── exclusions ──────────────────────────────────────────────────────────────────────────────────

    /**
     * Excluded twice over, by design: the path fragment AND the namespace segment. A fixture pinning central
     * is scaffolding for a test OF the mechanism, and asking it to cite a floor category would train authors
     * to paste categories they have not thought about.
     */
    public function test_a_test_fixture_path_is_excluded(): void
    {
        $this->assertSame([], $this->pins(<<<'PHP'
            <?php
            namespace Splicewire\Beam\Commerce\Tests\Support;
            class FixtureParty extends Model
            {
                protected $connection = 'central';
            }
            PHP, '/pkg/tests/Support/FixtureParty.php'));
    }

    public function test_a_test_support_namespace_under_src_is_excluded(): void
    {
        $this->assertSame([], $this->pins(<<<'PHP'
            <?php
            namespace Splicewire\Beam\Testing;
            class FakeCentralModel extends Model
            {
                protected $connection = 'central';
            }
            PHP, '/pkg/src/Testing/FakeCentralModel.php'));
    }

    public function test_a_test_class_is_excluded_even_on_a_non_test_path(): void
    {
        $this->assertSame([], $this->pins(<<<'PHP'
            <?php
            namespace App\Models;
            class CentralPinScanTest
            {
                protected $connection = 'central';
            }
            PHP, '/app/src/Models/CentralPinScanTest.php'));
    }

    // ── reporting, determinism, and the advisory contract ───────────────────────────────────────────

    /**
     * The census behind this ticket found 10 of 23 pins carrying no justification at all. That output is a
     * documentation backlog, and a documentation backlog that fails the build is just a blocked build — so
     * this audit emits `Warn`, never `Fail`, and registers advisory.
     */
    public function test_the_check_is_advisory_and_never_fails_a_build(): void
    {
        $audit = $this->scanning([
            'Uncited.php' => "<?php\nnamespace CentralPinScan;\nclass Uncited extends Model\n{\n    protected \$connection = 'central';\n}\n",
        ]);

        $findings = $audit->run();

        $this->assertNotEmpty($findings);

        foreach ($findings as $finding) {
            $this->assertSame(CentralPinJustificationAudit::CHECK, $finding->check);
            $this->assertNotSame(DoctorStatus::Fail, $finding->status);
        }

        $registrations = array_values(array_filter(
            $this->app->make(BeamDoctorManifest::class)->registrations(),
            fn ($registration) => $registration->audit === CentralPinJustificationAudit::class,
        ));

        $this->assertCount(1, $registrations, 'The audit must be registered so `surgeon:audit --json` finds it.');
        $this->assertFalse($registrations[0]->gate, 'The pin backlog must not gate the exit code.');
    }

    public function test_it_passes_when_there_is_nothing_to_report(): void
    {
        // The converged case: quiet, not silent.
        $findings = (new CentralPinJustificationAudit([sys_get_temp_dir().'/central-pin-scan-absent']))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_it_scans_real_files_from_disk_and_finds_both_forms_in_one_sweep(): void
    {
        $audit = $this->scanning([
            'PropertyPinned.php' => "<?php\nnamespace CentralPinScan;\nclass PropertyPinned extends Model\n{\n    protected \$connection = 'central';\n}\n",
            'ConstantPinned.php' => "<?php\nnamespace CentralPinScan;\nclass ConstantPinned\n{\n    const C = 'central';\n\n    public function q() { return Entry::on(self::C); }\n}\n",
            'JustifiedPin.php' => "<?php\nnamespace CentralPinScan;\nclass JustifiedPin extends Model\n{\n    /** @central-floor auth */\n    protected \$connection = 'central';\n}\n",
        ]);

        $forms = array_column($audit->pins(), 'form', 'class');

        $this->assertSame(CentralPinJustificationAudit::FORM_PROPERTY, $forms['CentralPinScan\\PropertyPinned'] ?? null);
        $this->assertSame(CentralPinJustificationAudit::FORM_CONSTANT, $forms['CentralPinScan\\ConstantPinned'] ?? null);

        $work = array_column($audit->unjustified(), 'class');
        $this->assertNotContains('CentralPinScan\\JustifiedPin', $work, 'A cited pin yields no work item.');
        $this->assertCount(2, $work);
    }

    public function test_running_twice_with_no_change_produces_an_identical_sorted_result(): void
    {
        $audit = $this->scanning([
            'Zebra.php' => "<?php\nnamespace CentralPinScan;\nclass Zebra extends Model\n{\n    protected \$connection = 'central';\n}\n",
            'Alpha.php' => "<?php\nnamespace CentralPinScan;\nclass Alpha extends Model\n{\n    protected \$connection = 'central';\n}\n",
        ]);

        $first = $audit->pins();

        $this->assertSame($first, $audit->pins());

        // Sorted by file then line, not filesystem-iteration order — otherwise a committed artifact churns
        // in review and its "the number only goes down" contract becomes unreadable.
        $this->assertSame(['CentralPinScan\\Alpha', 'CentralPinScan\\Zebra'], array_column($first, 'class'));
    }
}
