<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Surgeon\MorphTokenBypassAudit;

/**
 * The morph-token bypass audit. Pure — no disk, no container, no database.
 *
 * The audit exists because `tenant_sync_lineages.syncable_type` held 5,871 rows of fully-qualified
 * class names while `MorphAliasCoverageAudit` reported the aliases correctly present — which they were.
 * One coverage audit asks "does this model have an alias"; this one asks "does anything USE it".
 *
 * ⚠️ The load-bearing case is `test_a_bare_variable_...`. The defect that prompted this audit was
 * `'syncable_type' => $class` — a VARIABLE, not a `::class` literal — so an audit that only caught
 * class literals would have reported the estate clean while those 5,871 wrong rows sat there. The
 * negative cases matter just as much: correct callers must stay silent, or 49 findings become noise
 * nobody reads.
 */
class MorphTokenBypassAuditTest extends TestCase
{
    private function audit(): MorphTokenBypassAudit
    {
        return new MorphTokenBypassAudit;
    }

    /** @return list<string> the check keys the source produced */
    private function checks(string $source): array
    {
        return array_map(fn (array $h) => $h['check'], $this->audit()->hitsIn($source));
    }

    // ── positives ───────────────────────────────────────────────────────────────────────────────────

    public function test_a_bare_variable_written_into_a_type_column_is_flagged(): void
    {
        // Verbatim shape of the real defect (TenantSyncTarget), which produced 5,871 FQCN rows.
        $source = <<<'PHP'
        TenantSyncLineage::updateOrCreate([
            'syncable_type' => $class,
            'source_entity_id' => $sourceId,
        ], $attributes);
        PHP;

        $this->assertSame(['morph-token.raw-value'], $this->checks($source));
    }

    public function test_a_class_literal_queried_against_a_type_column_is_flagged(): void
    {
        $source = <<<'PHP'
        $lineage = TenantSyncLineage::where('syncable_type', Silo::class)->first();
        PHP;

        $this->assertSame(['morph-token.class-literal'], $this->checks($source));
    }

    public function test_a_dot_qualified_column_in_a_raw_join_is_flagged(): void
    {
        // A raw join is exactly where a hand-spelled token hides from a reader skimming for models.
        $source = <<<'PHP'
        $join->on('sib.siloable_id', '=', 'f.id')->where('sib.siloable_type', '=', Fragment::class);
        PHP;

        $this->assertSame(['morph-token.class-literal'], $this->checks($source));
    }

    // ── negatives — the half that decides whether anyone reads the findings ──────────────────────────

    public function test_asking_the_morph_map_is_not_a_finding(): void
    {
        $source = <<<'PHP'
        $join->where('sib.siloable_type', '=', (new Fragment)->getMorphClass());
        TenantSyncLineage::updateOrCreate(['syncable_type' => TenantSyncLineage::morphKeyFor($class)], $a);
        PHP;

        $this->assertSame([], $this->checks($source));
    }

    public function test_an_inline_string_token_is_not_a_finding(): void
    {
        // Already a token, not a class. Coupled to the alias never changing, which is a style point —
        // flagging it would bury the two checks that are real defects.
        $source = <<<'PHP'
        $join->where('sib.siloable_type', '=', 'fragment');
        DB::table('x')->insert(['siloable_type' => 'fragment']);
        PHP;

        $this->assertSame([], $this->checks($source));
    }

    public function test_a_column_that_merely_ends_in_type_with_a_scalar_is_not_a_finding(): void
    {
        // `content_type`, `grant_type` and friends are ordinary columns. Only a class-string or a bare
        // variable reaches a finding; a quoted scalar or a method call does not.
        $source = <<<'PHP'
        $payload = ['content_type' => 'application/json', 'grant_type' => $this->resolveGrantType()];
        PHP;

        $this->assertSame([], $this->checks($source));
    }

    public function test_clean_source_produces_nothing(): void
    {
        $this->assertSame([], $this->checks('<?php class Foo { public function bar() { return 1; } }'));
    }
}
