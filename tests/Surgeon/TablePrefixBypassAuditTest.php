<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Surgeon\TablePrefixBypassAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-facade ticket 19 — the seam leak check, and the shape ticket 10 measured at **~91% false
 * positives** naive (155 hits, ~141 illegitimate).
 *
 * `'beam_` is five checks wearing one regex: morph-map aliases, marketplace listing kinds, index names,
 * legacy `Schema::rename` targets, and actual table names. So the audit is constructed as a closed list
 * of positions where a string IS a table name — and the negative tests below are that construction's
 * acceptance data. The `Schema::rename` one is the sharpest: those arguments **must** stay literal or
 * the rename stops finding the table it is renaming.
 */
class TablePrefixBypassAuditTest extends TestCase
{
    /** @return list<array{line: int, form: string, table: string, stem: string}> */
    private function bypasses(string $source): array
    {
        return (new TablePrefixBypassAudit(new FacadeConformanceScope([])))->bypassesInSource($source);
    }

    public function test_it_flags_a_hardcoded_model_table(): void
    {
        $rows = $this->bypasses(<<<'PHP'
        <?php

        class BeamUxEntry
        {
            protected $table = 'beam_ux_entries';
        }
        PHP);

        $this->assertSame(
            [['line' => 5, 'form' => 'protected $table', 'table' => 'beam_ux_entries', 'stem' => 'ux_entries']],
            $rows,
        );
    }

    public function test_it_flags_the_schema_and_db_table_positions(): void
    {
        $rows = $this->bypasses(<<<'PHP'
        <?php

        if (Schema::hasTable('beam_ux_entries')) {
            Schema::table('beam_ux_entries', fn () => null);
        }
        Schema::create('beam_ux_entries', fn () => null);
        Schema::dropIfExists('beam_ux_entries');
        DB::table('beam_ux_entries')->truncate();
        PHP);

        $this->assertCount(5, $rows);
        $this->assertSame(
            ['Schema::hasTable()', 'Schema::table()', 'Schema::create()', 'Schema::dropIfExists()', 'DB::table()'],
            array_column($rows, 'form'),
        );
    }

    /**
     * The stub-and-its-model pair ticket 20 flagged as "the audit working as designed": two authored
     * origins of one bypass, both needing the fix. Do not dedupe them — fixing the migration alone
     * leaves the model pointing at the unprefixed name.
     */
    public function test_a_stub_and_its_model_are_two_findings_not_one(): void
    {
        $stub = $this->bypasses("<?php\n\nSchema::create('beam_billable', fn () => null);\n");
        $model = $this->bypasses("<?php\n\nclass BillingAccount { protected \$table = 'beam_billable'; }\n");

        $this->assertCount(1, $stub);
        $this->assertCount(1, $model);
        $this->assertSame('billable', $stub[0]['stem']);
        $this->assertSame('billable', $model[0]['stem']);
    }

    // ---- The must-not-flag acceptance data (ticket 10 §4) --------------------------------

    /**
     * **The one that would break production if flagged and then "fixed".** A `Schema::rename()`
     * argument names a table that already exists under its literal old name; routing it through the seam
     * would rename the wrong table, or nothing. `rename` is absent from the verb list deliberately.
     */
    public function test_it_does_not_flag_a_legacy_schema_rename(): void
    {
        $this->assertSame([], $this->bypasses("<?php\n\nSchema::rename('beam_market_packages_releases', 'beam_market_releases');\n"));
    }

    /** A morph-map alias is a wire identifier, not a table name — renaming it breaks stored `*_type` values. */
    public function test_it_does_not_flag_a_morph_map_alias(): void
    {
        $this->assertSame([], $this->bypasses("<?php\n\nRelation::morphMap(['beam_particle' => BeamParticle::class]);\n"));
    }

    /** A marketplace listing KIND that happens to spell `beam_`. */
    public function test_it_does_not_flag_a_listing_kind_literal(): void
    {
        $this->assertSame([], $this->bypasses("<?php\n\nclass Product { public const KIND = 'beam_extension'; }\n"));
    }

    /** An index name is not a table name, whatever it is prefixed with. */
    public function test_it_does_not_flag_an_index_name(): void
    {
        $this->assertSame([], $this->bypasses("<?php\n\n\$table->index(['a', 'b'], 'beam_ux_entries_a_b_index');\n"));
    }

    /** The seam resolving the name is the seam working. */
    public function test_it_does_not_flag_a_seam_resolved_table(): void
    {
        $this->assertSame([], $this->bypasses("<?php\n\nuse Splicewire\\Beam\\Facades\\Beam;\n\nSchema::create(Beam::table('ux_entries'), fn () => null);\n"));
    }

    /** A `$table` property naming something outside beam's prefix is another package's business. */
    public function test_it_does_not_flag_an_unprefixed_table(): void
    {
        $this->assertSame([], $this->bypasses("<?php\n\nclass Post { protected \$table = 'posts'; }\n"));
    }

    public function test_a_clean_scan_passes(): void
    {
        $findings = (new TablePrefixBypassAudit(new FacadeConformanceScope([])))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame(TablePrefixBypassAudit::CHECK, $findings[0]->check);
    }
}
