<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Rushing\SchemaConvergence\ColumnTypeEquivalence;
use Splicewire\Beam\Doctor\UnverifiedOnPopulatedTableAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-facade ticket 187 — the ROWS half of the `unverified` measurement.
 *
 * The fixture is a real fake host, for the reason `PackageStubConflictAuditTest` gives: the mechanism IS
 * composer's manifest, a glob, a `require` of an unpublished file, a live `getColumns()` and a live
 * `count`. Mocking any of them tests the mock.
 *
 * ⚠️ **The estate's live population is ONE pairing, so every case here is manufactured** — a verdict
 * reasoned against a zero-instance dataset is not a verdict. `enum` is used deliberately rather than an
 * invented type: it is the estate's real unmapped type, it is genuinely absent from
 * `ColumnTypeEquivalence::ACCEPTS`, and the test asserts that absence rather than assuming it, so the
 * day someone adds the mapping these cases fail loudly instead of passing vacuously.
 */
class UnverifiedOnPopulatedTableAuditTest extends TestCase
{
    private string $root;

    private string $package;

    private string $published;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/beam-unverified-populated-'.getmypid().'-'.bin2hex(random_bytes(4));
        $this->package = $this->root.'/vendor/acme/widgets';
        $this->published = $this->root.'/database/migrations';

        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->package.'/database/migrations');
        File::ensureDirectoryExists($this->published);
        File::ensureDirectoryExists($this->root.'/vendor/composer');

        File::put($this->root.'/vendor/composer/installed.json', json_encode([
            'packages' => [
                ['name' => 'acme/widgets', 'install-path' => '../acme/widgets'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        Schema::dropIfExists('widgets');

        parent::tearDown();
    }

    /** @return list<Finding> */
    private function audit(): array
    {
        return (new UnverifiedOnPopulatedTableAudit($this->root, [$this->published]))->run();
    }

    private function body(string $body): string
    {
        return <<<PHP
        <?php

        use Illuminate\\Database\\Migrations\\Migration;
        use Illuminate\\Database\\Schema\\Blueprint;
        use Illuminate\\Support\\Facades\\DB;
        use Illuminate\\Support\\Facades\\Schema;
        use Rushing\\SchemaConvergence\\ConvergentTable;

        {$body}
        PHP;
    }

    private function template(string $name, string $body): void
    {
        File::put($this->package.'/database/migrations/'.$name, $this->body($body));
    }

    private function publish(string $name, string $body): void
    {
        File::put($this->published.'/'.$name, $this->body($body));
    }

    /** The declaration whose `pin_mode` type the map has no entry for — the estate's real shape. */
    private function widgetsDeclaring(string $type): string
    {
        return <<<PHP
        return new class extends Migration
        {
            public function up(): void
            {
                ConvergentTable::named('widgets')
                    ->define(function (Blueprint \$table) {
                        \$table->uuid('id')->primary();
                        \$table->{$type}('pin_mode', ['head', 'pinned'])->nullable();
                    })
                    ->assert();
            }
        };
        PHP;
    }

    /** The live table, as Laravel actually compiles `enum()` — varchar plus a check, not an enum type. */
    private function liveWidgets(int $rows): void
    {
        Schema::create('widgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('pin_mode', ['head', 'pinned'])->nullable();
        });

        for ($i = 0; $i < $rows; $i++) {
            Schema::getConnection()->table('widgets')->insert([
                'id' => sprintf('00000000-0000-4000-8000-%012d', $i),
                'pin_mode' => 'head',
            ]);
        }
    }

    private function detailOf(array $findings, string $check): ?string
    {
        foreach ($findings as $finding) {
            if ($finding->check === $check) {
                return $finding->detail;
            }
        }

        return null;
    }

    public function test_the_type_this_whole_audit_turns_on_is_genuinely_absent_from_the_map(): void
    {
        // Rule 4: state what the zero means. If `enum` is ever mapped, every case below stops measuring
        // what it claims to measure — so the premise is asserted rather than assumed.
        $this->assertNotContains('enum', ColumnTypeEquivalence::mappedTypes());
        $this->assertNull(ColumnTypeEquivalence::matches('sqlite', 'enum', 'varchar'));
    }

    public function test_an_unmapped_type_on_a_populated_table_is_reported_with_the_pairing_named(): void
    {
        $this->liveWidgets(rows: 3);
        $this->publish('2026_01_01_000000_create_widgets_table.php', $this->widgetsDeclaring('enum'));

        $findings = $this->audit();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('1 column(s) across 1 table(s)', $findings[0]->detail);

        $pairing = $this->detailOf($findings, UnverifiedOnPopulatedTableAudit::CHECK.'.pairing');
        $this->assertNotNull($pairing);
        $this->assertStringContainsString('`widgets.pin_mode`', $pairing);
        $this->assertStringContainsString('HOLDS ROWS', $pairing);
        $this->assertStringContainsString('2026_01_01_000000_create_widgets_table (published here)', $pairing);
    }

    public function test_the_same_declaration_on_an_empty_table_is_not_a_pairing(): void
    {
        // The whole discriminator, manufactured both ways against one declaration: identical shape,
        // identical map verdict, zero rows. Without this case the audit could be reporting `unverified`
        // and never reading a row at all.
        $this->liveWidgets(rows: 0);
        $this->publish('2026_01_01_000000_create_widgets_table.php', $this->widgetsDeclaring('enum'));

        $findings = $this->audit();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('empty or absent', $findings[0]->detail);
    }

    public function test_a_mapped_type_on_a_populated_table_is_not_a_pairing(): void
    {
        $this->liveWidgets(rows: 3);
        $this->publish('2026_01_01_000000_create_widgets_table.php', <<<'PHP'
        return new class extends Migration
        {
            public function up(): void
            {
                ConvergentTable::named('widgets')
                    ->define(function (Blueprint $table) {
                        $table->uuid('id')->primary();
                    })
                    ->assert();
            }
        };
        PHP);

        $findings = $this->audit();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    public function test_a_template_and_the_copy_stamped_from_it_are_one_pairing_carrying_both_provenances(): void
    {
        // Two populations, one hazard. Summing them would report the estate's single pairing as two.
        $this->liveWidgets(rows: 3);
        $this->publish('2026_01_01_000000_create_widgets_table.php', $this->widgetsDeclaring('enum'));
        $this->template('create_widgets_table.php.stub', $this->widgetsDeclaring('enum'));

        $findings = $this->audit();

        $pairings = array_filter(
            $findings,
            fn ($f) => $f->check === UnverifiedOnPopulatedTableAudit::CHECK.'.pairing',
        );

        $this->assertCount(1, $pairings);
        $this->assertStringContainsString('1 column(s) across 1 table(s)', $findings[0]->detail);
        $this->assertStringContainsString('(published here)', $findings[0 + 1]->detail);
        $this->assertStringContainsString('template shipped by acme/widgets', $findings[1]->detail);
        $this->assertStringContainsString('1 published copy/copies', $findings[0]->detail);
        $this->assertStringContainsString('1 package template(s)', $findings[0]->detail);
    }

    public function test_a_template_alone_is_reached_even_though_no_migrate_will_ever_run_it(): void
    {
        // The population `MigrationFiles` cannot see: a host override holds the stem forever, so the
        // stub drops out of what `migrate` reads and only a republish would run it.
        $this->liveWidgets(rows: 3);
        $this->template('create_widgets_table.php.stub', $this->widgetsDeclaring('enum'));

        $findings = $this->audit();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString(
            'template shipped by acme/widgets',
            (string) $this->detailOf($findings, UnverifiedOnPopulatedTableAudit::CHECK.'.pairing'),
        );
    }

    public function test_a_body_that_rehearses_and_yields_no_report_is_counted_as_unread_not_as_clean(): void
    {
        // The defect this audit is built against: a file that named the guard, ran without error, and
        // taught the instrument nothing, folded silently into the rehearsed count.
        $this->publish('2026_01_01_000000_create_widgets_table.php', <<<'PHP'
        return new class extends Migration
        {
            public function up(): void
            {
                if (false) {
                    ConvergentTable::named('widgets')->define(fn (Blueprint $t) => $t->uuid('id'))->assert();
                }
            }
        };
        PHP);

        $findings = $this->audit();

        $reach = $this->detailOf($findings, UnverifiedOnPopulatedTableAudit::CHECK.'.reach');
        $this->assertNotNull($reach);
        $this->assertStringContainsString('1 rehearsed and yielded NO convergence report', $reach);
        $this->assertStringContainsString('Read nothing out of: 2026_01_01_000000_create_widgets_table.php', $reach);
    }

    public function test_the_reach_line_is_emitted_beside_a_clean_run_too(): void
    {
        // A counter that only appears when there is already something to report is not a counter.
        $this->liveWidgets(rows: 0);
        $this->publish('2026_01_01_000000_create_widgets_table.php', $this->widgetsDeclaring('enum'));

        $findings = $this->audit();

        $reach = $this->detailOf($findings, UnverifiedOnPopulatedTableAudit::CHECK.'.reach');
        $this->assertNotNull($reach);
        $this->assertStringContainsString('1 of 1 convergent declaration(s) were rehearsed', $reach);
    }

    public function test_an_unrehearsable_body_is_skipped_and_the_check_says_it_verified_nothing(): void
    {
        // RehearsalSafety refuses this and must keep refusing it — the raw statement would run for real
        // during a pass that promised to write nothing.
        $this->publish('2026_01_01_000000_create_widgets_table.php', <<<'PHP'
        return new class extends Migration
        {
            public function up(): void
            {
                ConvergentTable::named('widgets')->define(fn (Blueprint $t) => $t->uuid('id'))->assert();
                DB::statement('CREATE UNIQUE INDEX widgets_idx ON widgets (id)');
            }
        };
        PHP);

        $findings = $this->audit();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('verified NOTHING', $findings[0]->detail);
    }

    public function test_a_host_declaring_nothing_convergent_passes_and_names_the_half_it_cannot_reach(): void
    {
        $findings = $this->audit();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('unmapped-convergent-type', $findings[0]->detail);
    }

    public function test_the_advisory_never_gates_whatever_it_finds(): void
    {
        // `gate-or-advisory.convention.md`: whether a table holds rows HERE is a host fact. This is the
        // regression guard for the severity, because the argument to escalate it lives one ticket away.
        $this->liveWidgets(rows: 3);
        $this->publish('2026_01_01_000000_create_widgets_table.php', $this->widgetsDeclaring('enum'));

        foreach ($this->audit() as $finding) {
            $this->assertNotSame(DoctorStatus::Fail, $finding->status);
        }
    }
}
