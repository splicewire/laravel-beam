<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\StubStaticReferenceAudit;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-facade ticket 19 — the durability half of ticket 07's option B3, the reason ticket 10 could not
 * be ruled out of scope.
 *
 * The scenario this exists for is not hypothetical: ticket 15 swept the 39 stubs and found one born
 * importing the bridge *during the sweep*, and ticket 16 found 22 host migrations published from stubs
 * the day before those stubs were fixed. The estate publishes faster than it sweeps.
 */
class StubStaticReferenceAuditTest extends TestCase
{
    private function audit(): StubStaticReferenceAudit
    {
        return new StubStaticReferenceAudit(new FacadeConformanceScope([]));
    }

    /** @return list<array{line: int, kind: string}> */
    private function refs(string $source): array
    {
        return $this->audit()->referencesInSource($source);
    }

    public function test_it_flags_an_import_of_the_deleted_bridge(): void
    {
        $rows = $this->refs(<<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Splicewire\Beam\Beam;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create(Beam::table('widgets'), function () {});
            }
        };
        PHP);

        // Both the import and the call under it: `Beam::table()` tokenizes as a bare name carrying no
        // namespace, so the scanner reads the file's own `use` map rather than qualified names alone.
        $this->assertSame([['line' => 4, 'kind' => 'code'], ['line' => 10, 'kind' => 'code']], $rows);
    }

    /**
     * The whole point of the class taking a distinct name: a stub importing the FACADE is conformant, and
     * an exact-match check is what keeps `Splicewire\Beam\Facades\Beam` from answering to a search for
     * `Splicewire\Beam\Beam`. That ambiguity is what CONTEXT.md exists to end.
     */
    public function test_it_does_not_flag_the_facade(): void
    {
        $this->assertSame([], $this->refs(<<<'PHP'
        <?php

        use Splicewire\Beam\Facades\Beam;

        Schema::create(Beam::table('widgets'), function () {});
        PHP));
    }

    /**
     * Ticket 07 ruled that **loose prose naming `Beam::table()` stays** — the call syntax never changed,
     * so a sentence about it is not stale — and swept only machine-resolved references. A comment is a
     * token type, so this falls out of the substrate rather than needing an exception.
     */
    public function test_it_does_not_flag_prose(): void
    {
        $this->assertSame([], $this->refs(<<<'PHP'
        <?php

        /**
         * Table names resolve through Beam::table(), which is beam core's job.
         * Historically this was Splicewire\Beam\Beam, a static-only helper.
         */
        return new class {};
        PHP));
    }

    /**
     * A `{@see}` or `@method` tag is a machine-resolved reference — the kind the sweep moved — so a tag
     * pointing at the deleted class is drift even though it is inside a comment. This is the line
     * between "prose about a call" and "a pointer to a symbol that no longer exists".
     */
    public function test_it_flags_a_resolved_docblock_tag_naming_the_bridge(): void
    {
        $rows = $this->refs(<<<'PHP'
        <?php

        /**
         * Prefixing is beam core's job.
         *
         * @see \Splicewire\Beam\Beam::table()
         */
        return new class {};
        PHP);

        $this->assertSame([['line' => 3, 'kind' => 'doc-tag']], $rows);
    }

    /** The same tag pointing at the facade resolves fine and is not drift. */
    public function test_it_does_not_flag_a_resolved_docblock_tag_naming_the_facade(): void
    {
        $this->assertSame([], $this->refs(<<<'PHP'
        <?php

        /** @see \Splicewire\Beam\Facades\Beam::table() */
        return new class {};
        PHP));
    }

    /**
     * Ticket 15 found the stub population carries no placeholder tokens at all (39/39 `php -l` clean,
     * matching 16's 62/62 on the dated population), so the tolerance ticket 03 feared never had to be
     * built. It survives as a posture: an unparseable file goes quiet rather than noisy. An advisory
     * drift check has no business reporting on a file's syntax.
     */
    public function test_an_unparseable_template_yields_no_findings_rather_than_a_syntax_complaint(): void
    {
        $this->assertSame([], $this->refs("<?php\n\nuse Splicewire\\Beam\\Beam;\n\nclass {{ NAME }} extends {{\n"));
    }

    public function test_a_clean_scan_passes_and_states_what_it_covered(): void
    {
        $findings = (new StubStaticReferenceAudit(new FacadeConformanceScope([])))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame(StubStaticReferenceAudit::CHECK, $findings[0]->check);
        // "no findings" and "found nothing to look at" are different facts; a scope that silently
        // collapsed to zero roots must not read as conformance.
        $this->assertStringContainsString('0 stub(s) scanned', $findings[0]->detail);
    }
}
