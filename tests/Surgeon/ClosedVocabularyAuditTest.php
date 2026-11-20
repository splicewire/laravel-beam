<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Surgeon\ClosedVocabularyAudit;

/**
 * The closed-vocabulary audit (attention-surfaces 16). Pure unit test — no Laravel boot, no testbench
 * provider list — so it cannot fall into the estate's own auto-discovery trap: the audit is constructed
 * with its file set directly, exactly as the sweep's binding does. Fixtures reproduce each class of
 * literal in shape; nothing is asserted against a live estate file.
 */
class ClosedVocabularyAuditTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->rrmdir($root);
        }
        $this->roots = [];

        parent::tearDown();
    }

    public function test_it_warns_a_union_of_two_or_more_quoted_string_literals(): void
    {
        $file = $this->dataClass('Vocab.php', 'Vocab', <<<'PHP'
                #[LiteralTypeScriptType("'a' | 'b' | 'c'")] public string $v,
                #[LiteralTypeScriptType("'x' | 'y' | null")] public ?string $w,
            PHP);

        $findings = $this->findings([$file], ClosedVocabularyAudit::CHECK_VOCABULARY);

        $this->assertCount(2, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('Vocab::v', $findings[0]->detail);
        $this->assertStringContainsString('Vocab::w', $findings[1]->detail); // `null` does not disqualify it
    }

    public function test_it_warns_an_inline_object_with_named_members(): void
    {
        $file = $this->dataClass('Obj.php', 'Obj', <<<'PHP'
                #[LiteralTypeScriptType("{ foo: string; bar: number }")] public array $o,
            PHP);

        $findings = $this->findings([$file], ClosedVocabularyAudit::CHECK_INLINE_OBJECT);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('Obj::o', $findings[0]->detail);
    }

    public function test_it_warns_a_single_literal_whose_default_is_missing_or_different(): void
    {
        $file = $this->dataClass('Disc.php', 'Disc', <<<'PHP'
                #[LiteralTypeScriptType("'card'")] public string $wrong = 'cash',
                #[LiteralTypeScriptType("'card'")] public string $missing,
            PHP);

        $findings = $this->findings([$file], ClosedVocabularyAudit::CHECK_DISCRIMINATOR_MISMATCH);

        $this->assertCount(2, $findings);
        $this->assertStringContainsString('Disc::wrong', $findings[0]->detail);
        $this->assertStringContainsString('Disc::missing', $findings[1]->detail);
    }

    public function test_it_passes_a_single_literal_that_equals_its_default(): void
    {
        $file = $this->dataClass('Ok.php', 'Ok', <<<'PHP'
                #[LiteralTypeScriptType("'card'")] public string $kind = 'card',
            PHP);

        // No Warn of any kind; the census counts it as a discriminator.
        $this->assertSame([], $this->warnings([$file]));
        $this->assertStringContainsString('1 discriminator', $this->census([$file]));
    }

    public function test_it_passes_a_numeric_literal_and_counts_it_a_version(): void
    {
        $file = $this->dataClass('Ver.php', 'Ver', <<<'PHP'
                #[LiteralTypeScriptType("2")] public int $v,
            PHP);

        $this->assertSame([], $this->warnings([$file]));
        $this->assertStringContainsString('1 version', $this->census([$file]));
    }

    public function test_it_passes_a_structural_type_and_counts_it_structural(): void
    {
        $file = $this->dataClass('Struct.php', 'Struct', <<<'PHP'
                #[LiteralTypeScriptType("list<string>")] public array $l,
            PHP);

        $this->assertSame([], $this->warnings([$file]));
        $this->assertStringContainsString('1 structural', $this->census([$file]));
    }

    public function test_it_passes_an_inference_override_and_counts_it_apart(): void
    {
        $file = $this->dataClass('Inf.php', 'Inf', <<<'PHP'
                #[LiteralTypeScriptType("unknown")] public mixed $u,
            PHP);

        $this->assertSame([], $this->warnings([$file]));
        $this->assertStringContainsString('1 inference-override', $this->census([$file]));
    }

    public function test_it_warns_a_data_class_docblock_literal_union_with_no_attribute(): void
    {
        $file = $this->php('Dock.php', <<<'PHP'
            <?php
            namespace Acme;
            use Spatie\TypeScriptTransformer\Attributes\TypeScript;
            #[TypeScript]
            class Dock {
                /** @param  'ltr'|'rtl'  $state  the direction */
                public function __construct(
                    public string $state,
                ) {}
            }
            PHP);

        $findings = $this->findings([$file], ClosedVocabularyAudit::CHECK_DOCBLOCK_VOCABULARY);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('Dock::state', $findings[0]->detail);
    }

    public function test_it_finds_the_attribute_fully_qualified_second_in_group_and_class_level(): void
    {
        $fq = $this->php('Fq.php', <<<'PHP'
            <?php
            namespace Acme;
            use Spatie\TypeScriptTransformer\Attributes\TypeScript;
            #[TypeScript]
            class Fq {
                public function __construct(
                    #[\Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType("'p' | 'q'")] public string $fq,
                ) {}
            }
            PHP);

        $group = $this->php('Grp.php', <<<'PHP'
            <?php
            namespace Acme;
            use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
            use Spatie\TypeScriptTransformer\Attributes\TypeScript;
            #[TypeScript]
            class Grp {
                public function __construct(
                    #[\JsonSerializable, LiteralTypeScriptType("'a' | 'b'")] public string $g,
                ) {}
            }
            PHP);

        $classLevel = $this->php('Cls.php', <<<'PHP'
            <?php
            namespace Acme;
            use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
            #[LiteralTypeScriptType("'one' | 'two'")]
            class Cls {}
            PHP);

        $vocab = $this->findings([$fq, $group, $classLevel], ClosedVocabularyAudit::CHECK_VOCABULARY);

        $this->assertCount(3, $vocab);
        $details = implode("\n", array_map(fn ($f) => $f->detail, $vocab));
        $this->assertStringContainsString('Fq::fq', $details);          // fully qualified
        $this->assertStringContainsString('Grp::g', $details);          // second in the group
        $this->assertStringContainsString('Cls::<class>', $details);    // class level
    }

    public function test_an_empty_scan_returns_an_inconclusive_finding_not_an_empty_list(): void
    {
        $findings = (new ClosedVocabularyAudit([]))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(ClosedVocabularyAudit::CHECK_CENSUS, $findings[0]->check);
        $this->assertFalse($findings[0]->conclusive);
    }

    public function test_the_census_is_emitted_on_every_run_and_counts_each_class_apart(): void
    {
        $file = $this->dataClass('All.php', 'All', <<<'PHP'
                #[LiteralTypeScriptType("'a' | 'b'")] public string $vocab,
                #[LiteralTypeScriptType("{ x: string }")] public array $obj,
                #[LiteralTypeScriptType("'k'")] public string $disc = 'k',
                #[LiteralTypeScriptType("3")] public int $ver,
                #[LiteralTypeScriptType("list<int>")] public array $struct,
                #[LiteralTypeScriptType("unknown")] public mixed $inf,
            PHP);

        $census = $this->census([$file]);

        $this->assertStringContainsString('1 discriminator', $census);
        $this->assertStringContainsString('1 version', $census);
        $this->assertStringContainsString('1 structural', $census);
        $this->assertStringContainsString('1 inference-override', $census);
        $this->assertStringContainsString('1 literal-vocabulary', $census);
        $this->assertStringContainsString('1 inline-object', $census);
    }

    /* ---------------- helpers ---------------- */

    /** @return list<\Rushing\Doctor\Finding> */
    private function findings(array $files, ?string $check = null): array
    {
        $findings = (new ClosedVocabularyAudit($files))->run();

        if ($check === null) {
            return $findings;
        }

        return array_values(array_filter($findings, fn ($f) => $f->check === $check));
    }

    /** @return list<\Rushing\Doctor\Finding> every Warn finding. */
    private function warnings(array $files): array
    {
        return array_values(array_filter(
            (new ClosedVocabularyAudit($files))->run(),
            fn ($f) => $f->status === DoctorStatus::Warn,
        ));
    }

    private function census(array $files): string
    {
        $census = $this->findings($files, ClosedVocabularyAudit::CHECK_CENSUS);
        $this->assertCount(1, $census, 'the census line must be emitted on every run');

        return $census[0]->detail;
    }

    /** A #[TypeScript] Data class whose constructor body is $members (promoted params). */
    private function dataClass(string $name, string $class, string $members): string
    {
        return $this->php($name, <<<PHP
            <?php
            namespace Acme;
            use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
            use Spatie\TypeScriptTransformer\Attributes\TypeScript;
            #[TypeScript]
            class {$class} {
                public function __construct(
            {$members}
                ) {}
            }
            PHP);
    }

    private function php(string $name, string $contents): string
    {
        $root = sys_get_temp_dir().'/closed-vocabulary-'.getmypid().'-'.count($this->roots);
        @mkdir($root, 0777, true);
        $this->roots[] = $root;

        $path = $root.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
