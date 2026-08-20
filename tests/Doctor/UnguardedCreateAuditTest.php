<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Doctor\Support\SchemaCreateScanner;
use Splicewire\Beam\Doctor\UnguardedCreateAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-facade ticket 30 — the sixth audit, and the static half of the migration-collision family that
 * tickets 27–29 closed at its two runtime mechanisms.
 *
 * The scenario is not hypothetical and was not a forecast: ticket 28 swept 118 stubs onto the guard and
 * ticket 30's own census then found a **live unguarded create the sweep had walked past**, because 22
 * specified the population as `create_*` stubs and `splicewire/tower`'s
 * `add_directory_acl_grants_and_visibility.php.stub` creates a table called `grants` beside an ALTER.
 * The filename was honest; the profile was wrong.
 */
class UnguardedCreateAuditTest extends TestCase
{
    /** @return list<array{line: int, shape: string}> */
    private function creates(string $source): array
    {
        return SchemaCreateScanner::createCalls($source);
    }

    public function test_it_flags_a_raw_schema_create(): void
    {
        $rows = $this->creates(<<<'PHP'
        <?php

        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class
        {
            public function up(): void
            {
                Schema::create('grants', function (Blueprint $table) {
                    $table->id();
                });
            }
        };
        PHP);

        $this->assertSame([['line' => 10, 'shape' => 'Schema::create']], $rows);
    }

    /**
     * The dynamic-name refusal, made concrete. Nearly every Beam table is named through the prefix seam,
     * so a check keyed on table identity would be blind to almost the whole estate —
     * `MigrationOrderingAudit::tablesIn()` declines to resolve this shape for the same reason. Nothing
     * here asks what the table is called.
     */
    public function test_it_flags_a_dynamically_named_create(): void
    {
        $rows = $this->creates(<<<'PHP'
        <?php

        use Illuminate\Support\Facades\Schema;
        use Splicewire\Beam\Facades\Beam;

        Schema::create(Beam::table('submissions'), function () {});
        PHP);

        $this->assertSame([['line' => 6, 'shape' => 'Schema::create']], $rows);
    }

    /** A stub on the guard does not wrap the create, it replaces it — so there is nothing left to match. */
    public function test_it_does_not_flag_a_convergent_create(): void
    {
        $this->assertSame([], $this->creates(<<<'PHP'
        <?php

        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;
        use Rushing\SchemaConvergence\ConvergentTable;
        use Splicewire\Beam\Facades\Beam;

        return new class
        {
            public function up(): void
            {
                ConvergentTable::named(Beam::table('versions'))
                    ->define(function (Blueprint $table) {
                        $table->uuid('id')->primary();
                    })
                    ->assert();
            }

            public function down(): void
            {
                Schema::dropIfExists(Beam::table('versions'));
            }
        };
        PHP));
    }

    /**
     * Ticket 28's defect a presence check cannot see: `create_permission_tables` guarded on `permissions`
     * and returned for all five of its creates, so a host owning `roles` kept the bigint wall and the
     * guard reported success. Per call, not per file — a half-converted file reports exactly its
     * unconverted creates.
     */
    public function test_it_flags_the_unconverted_create_in_a_half_converted_file(): void
    {
        $rows = $this->creates(<<<'PHP'
        <?php

        use Illuminate\Support\Facades\Schema;
        use Rushing\SchemaConvergence\ConvergentTable;

        return new class
        {
            public function up(): void
            {
                ConvergentTable::named('permissions')->define(fn () => null)->assert();

                Schema::create('roles', function () {});
            }
        };
        PHP);

        $this->assertSame([['line' => 12, 'shape' => 'Schema::create']], $rows);
    }

    /**
     * `Attribute::create()` seeding a row is not a schema create, and the estate carries two of them in
     * one `splicewire/laravel-beam-market` seed stub. Checking the receiver rather than the method name
     * is what keeps a data migration out of a schema check.
     */
    public function test_it_does_not_flag_an_eloquent_create(): void
    {
        $this->assertSame([], $this->creates(<<<'PHP'
        <?php

        use Illuminate\Support\Facades\Schema;
        use Splicewire\Beam\Market\Models\Attribute;

        return new class
        {
            public function up(): void
            {
                Attribute::create(['handle' => 'grant']);
            }
        };
        PHP));
    }

    /** The same create reached one hop further out. Both shapes, one rule. */
    public function test_it_flags_a_create_on_an_explicit_connection(): void
    {
        $rows = $this->creates(<<<'PHP'
        <?php

        use Illuminate\Support\Facades\Schema;

        Schema::connection(config('beam.core.connection'))->create('grants', function () {});
        PHP);

        $this->assertSame([['line' => 5, 'shape' => 'Schema::connection()->create']], $rows);
    }

    /**
     * A docblock explaining what the file used to do is prose, not code. `token_get_all()` draws that
     * line where PHP does — the reason 19 built this regime on tokens rather than regex, after measuring
     * a naive check at ~69% false positives.
     */
    public function test_it_does_not_flag_prose(): void
    {
        $this->assertSame([], $this->creates(<<<'PHP'
        <?php

        use Illuminate\Support\Facades\Schema;

        /**
         * This used to be a bare Schema::create('grants', …) and lost the filename race to lunar.
         */
        return new class {};
        PHP));
    }

    /**
     * Requiring the import is a deliberate narrowing rather than an oversight: a migration template lives
     * in the global namespace, so an unimported `Schema` resolves to a global class that does not exist
     * and the file fatals on its own. There is no silent shape behind the requirement — but a *different*
     * `Schema` reached through a different import is a real class, and must not answer for the facade.
     */
    public function test_it_does_not_flag_another_vendors_schema_class(): void
    {
        $this->assertSame([], $this->creates(<<<'PHP'
        <?php

        use Schemastud\DataSchemas\Schema;

        Schema::create(['handle' => 'intake']);
        PHP));
    }

    /** 19's posture, inherited verbatim: an advisory drift check has no business reporting on syntax. */
    public function test_an_unparseable_template_yields_no_findings_rather_than_a_syntax_complaint(): void
    {
        $this->assertSame([], $this->creates("<?php\n\nuse Illuminate\\Support\\Facades\\Schema;\n\nclass {{ NAME }} extends {{\n"));
    }

    public function test_a_clean_scan_passes_and_states_what_it_covered_and_what_it_did_not(): void
    {
        $findings = (new UnguardedCreateAudit(new FacadeConformanceScope([])))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame(UnguardedCreateAudit::CHECK, $findings[0]->check);
        $this->assertStringContainsString('0 template(s) scanned', $findings[0]->detail);
        // 28 measured both gaps; a Pass that stays quiet about them certifies more than it checked.
        $this->assertStringContainsString('raw DDL', $findings[0]->detail);
        $this->assertStringContainsString('published into a host', $findings[0]->detail);
    }

    /**
     * The population rule ticket 22 got wrong and ticket 28 inherited: a template earns the check by
     * being a migration template, never by being named `create_*`. Tower's live find was an `add_*` file.
     */
    public function test_the_population_is_keyed_on_path_not_filename(): void
    {
        $this->assertTrue(UnguardedCreateAudit::isMigrationTemplate(
            '/x/database/migrations/tenant/add_directory_acl_grants_and_visibility.php.stub',
        ));
        $this->assertFalse(UnguardedCreateAudit::isMigrationTemplate(
            '/x/database/migrations/2026_08_09_120000_create_beam_versions_table.php',
        ));
        $this->assertFalse(UnguardedCreateAudit::isMigrationTemplate('/x/stubs/particle.php.stub'));
    }
}
