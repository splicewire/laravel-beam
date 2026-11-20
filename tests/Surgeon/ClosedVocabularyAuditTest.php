<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Surgeon\ClosedVocabularyAudit;

/**
 * The closed-vocabulary audit (attention-surfaces 16, with the validation-vocabulary check ruled in from
 * 26). Pure unit test — no Laravel boot, no testbench provider list — so it cannot fall into the estate's
 * own auto-discovery trap: the audit is constructed with its file set directly, exactly as the sweep's
 * binding does. Fixtures reproduce each class of literal and each parsing edge the cross-review named.
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

    /* ---------------- the four LiteralTypeScriptType checks ---------------- */

    public function test_it_warns_a_union_of_two_or_more_quoted_string_literals_including_wrapped_and_escaped(): void
    {
        $file = $this->dataClass('Vocab.php', 'Vocab', <<<'PHP'
                #[LiteralTypeScriptType("'a' | 'b' | 'c'")] public string $v,
                #[LiteralTypeScriptType("'x' | 'y' | null")] public ?string $w,
                #[LiteralTypeScriptType("list<'a'|'b'>")] public array $list,
                #[LiteralTypeScriptType("('p'|'q')[]")] public array $arr,
                #[LiteralTypeScriptType('\'e1\' | \'e2\'')] public string $esc,
            PHP);

        $v = $this->findings([$file], ClosedVocabularyAudit::CHECK_VOCABULARY);

        $this->assertCount(5, $v); // list<…> and (…)[] are closed vocabularies; an escaped quote is parsed
        $this->assertSame(DoctorStatus::Warn, $v[0]->status);
        foreach (['Vocab::v', 'Vocab::w', 'Vocab::list', 'Vocab::arr', 'Vocab::esc'] as $i => $where) {
            $this->assertStringContainsString($where, $v[$i]->detail);
        }
    }

    public function test_it_warns_inline_objects_named_quoted_readonly_and_the_array_form(): void
    {
        $file = $this->dataClass('Obj.php', 'Obj', <<<'PHP'
                #[LiteralTypeScriptType("{ foo: string; bar: number }")] public array $named,
                #[LiteralTypeScriptType("{ readonly foo: string }")] public array $ro,
                #[LiteralTypeScriptType("{ 'foo-bar': number }")] public array $quoted,
                #[LiteralTypeScriptType(['foo' => 'string', 'bar' => 'number'])] public array $arrayForm,
            PHP);

        $o = $this->findings([$file], ClosedVocabularyAudit::CHECK_INLINE_OBJECT);

        $this->assertCount(4, $o);
    }

    public function test_it_warns_a_single_literal_whose_default_is_missing_or_different_and_passes_an_equal_default(): void
    {
        $file = $this->dataClass('Disc.php', 'Disc', <<<'PHP'
                #[LiteralTypeScriptType("'card'")] public string $wrong = 'cash',
                #[LiteralTypeScriptType("'card'")] public string $missing,
                #[LiteralTypeScriptType("'card'")] public string $ok = 'card',
            PHP);

        $m = $this->findings([$file], ClosedVocabularyAudit::CHECK_DISCRIMINATOR_MISMATCH);
        $this->assertCount(2, $m);
        $this->assertStringContainsString('Disc::wrong', $m[0]->detail);
        $this->assertStringContainsString('Disc::missing', $m[1]->detail);
        $this->assertStringContainsString('1 discriminator,', $this->census([$file])); // $ok is a clean discriminator
    }

    public function test_a_constant_default_is_resolved_or_counted_unclassified_not_compared_as_text(): void
    {
        $file = $this->dataClass('Const.php', 'ConstDefault', <<<'PHP'
                #[LiteralTypeScriptType("'card'")] public string $kind = self::KIND,
            PHP, "\n    const KIND = 'card';");

        // The constant default is not comparable by value here, so the site is not warned as a mismatch;
        // it is counted unclassified rather than compared as text.
        $this->assertSame([], $this->findings([$file], ClosedVocabularyAudit::CHECK_DISCRIMINATOR_MISMATCH));
        $this->assertStringContainsString('1 unclassified', $this->census([$file]));
    }

    public function test_numeric_structural_and_inference_override_arguments_are_counted_apart(): void
    {
        $file = $this->dataClass('Mix.php', 'Mix', <<<'PHP'
                #[LiteralTypeScriptType("2")] public int $ver,
                #[LiteralTypeScriptType("list<string>")] public array $struct,
                #[LiteralTypeScriptType("Record<string, unknown>")] public array $rec,
                #[LiteralTypeScriptType("unknown")] public mixed $any,
            PHP);

        $census = $this->census([$file]);
        $this->assertSame([], $this->warnings([$file]));
        $this->assertStringContainsString('1 version', $census);
        $this->assertStringContainsString('1 structural', $census);
        $this->assertStringContainsString('2 inference-override', $census); // Record<…, unknown> joins the overrides (F19)
    }

    public function test_it_warns_a_data_class_docblock_literal_union_only_for_a_string_constructor_param(): void
    {
        $file = $this->php('Dock.php', <<<'PHP'
            <?php
            namespace Acme;
            use Spatie\TypeScriptTransformer\Attributes\TypeScript;
            #[TypeScript]
            class Dock {
                /** @param 'ltr'|'rtl' $state   @param 'a'|'b' $count */
                public function __construct(
                    public string $state,
                    public int $count,
                ) {}
                /** @param 'x'|'y' $other */
                public function method(string $other): void {}
            }
            PHP);

        $d = $this->findings([$file], ClosedVocabularyAudit::CHECK_DOCBLOCK_VOCABULARY);
        $this->assertCount(1, $d); // only $state: $count is int, $other is a non-constructor parameter
        $this->assertStringContainsString('Dock::state', $d[0]->detail);
    }

    /* ---------------- parsing edges the cross-review named ---------------- */

    public function test_it_finds_the_attribute_aliased_fully_qualified_second_in_group_class_level_and_multiline(): void
    {
        $alias = $this->php('Alias.php', <<<'PHP'
            <?php
            namespace Acme;
            use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType as L;
            use Spatie\TypeScriptTransformer\Attributes\TypeScript;
            #[TypeScript]
            class Alias {
                public function __construct(
                    #[L("'a' | 'b'")] public string $a,
                    #[\JsonSerializable, L("'c' | 'd'")] public string $second,
                    #[\Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType(
                        "'e' | 'f'"
                    )] public string $multiline,
                ) {}
            }
            PHP);

        // Several class-level groups, and a ::class in a method body that must not be read as a declaration.
        $classLevel = $this->php('Cls.php', <<<'PHP'
            <?php
            namespace Acme;
            use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
            use Spatie\TypeScriptTransformer\Attributes\TypeScript;
            #[TypeScript]
            #[LiteralTypeScriptType("'one' | 'two'")]
            class Cls {
                public function kind(): string { return Other::class; }
            }
            PHP);

        $v = $this->findings([$alias, $classLevel], ClosedVocabularyAudit::CHECK_VOCABULARY);
        $details = implode("\n", array_map(fn ($f) => $f->detail, $v));
        $this->assertCount(4, $v);
        $this->assertStringContainsString('Alias::a', $details);          // aliased import
        $this->assertStringContainsString('Alias::second', $details);     // second in the group
        $this->assertStringContainsString('Alias::multiline', $details);  // multi-line attribute, fully qualified
        $this->assertStringContainsString('Cls::<class>', $details);      // class level, several groups
    }

    public function test_a_named_argument_is_read_like_the_first_positional(): void
    {
        $file = $this->dataClass('Named.php', 'Named', <<<'PHP'
                #[LiteralTypeScriptType(typeScript: "'a' | 'b'")] public string $n,
            PHP);

        $this->assertCount(1, $this->findings([$file], ClosedVocabularyAudit::CHECK_VOCABULARY));
    }

    public function test_a_non_promoted_constructor_parameter_with_the_attribute_is_a_site(): void
    {
        $file = $this->php('NonPromoted.php', <<<'PHP'
            <?php
            namespace Acme;
            use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
            use Spatie\TypeScriptTransformer\Attributes\TypeScript;
            #[TypeScript]
            class NonPromoted {
                public string $kind;
                public function __construct(
                    #[LiteralTypeScriptType("'a' | 'b'")] string $kind,
                ) { $this->kind = $kind; }
            }
            PHP);

        $this->assertCount(1, $this->findings([$file], ClosedVocabularyAudit::CHECK_VOCABULARY));
    }

    public function test_a_name_substring_or_unrelated_alias_does_not_match(): void
    {
        $file = $this->php('Boundary.php', <<<'PHP'
            <?php
            namespace Acme;
            use Some\Url as URL;
            #[TypeScript]
            class Boundary {
                public function __construct(
                    #[FooLiteralTypeScriptType("'a' | 'b'")] public string $a,
                    #[URL("'c' | 'd'")] public string $b,
                ) {}
            }
            PHP);

        // Neither a substring match (FooLiteralTypeScriptType) nor an unrelated alias (URL) is the attribute.
        $this->assertSame([], $this->warnings([$file]));
    }

    /* ---------------- the validation-vocabulary check (fifth, ruled in from 26) ---------------- */

    public function test_it_warns_an_in_vocabulary_inline_varargs_constant_fq_and_second_in_group(): void
    {
        $file = $this->php('In.php', <<<'PHP'
            <?php
            namespace Acme;
            use Spatie\LaravelData\Attributes\Validation\In;
            use Spatie\LaravelData\Attributes\Validation\Title;
            use Spatie\TypeScriptTransformer\Attributes\TypeScript;
            #[TypeScript]
            class InData {
                const KINDS = ['red', 'green'];
                public function __construct(
                    #[In(['a', 'b'])] public string $inline,
                    #[In('x', 'y', 'z')] public string $varargs,
                    #[In(self::KINDS)] public string $constant,
                    #[\Spatie\LaravelData\Attributes\Validation\In(['m', 'n'])] public string $fq,
                    #[Title('t'), In(['g', 'h'])] public string $second,
                ) {}
            }
            PHP);

        $v = $this->findings([$file], ClosedVocabularyAudit::CHECK_VALIDATION_VOCABULARY);
        $this->assertCount(5, $v);
        $this->assertSame(DoctorStatus::Warn, $v[0]->status);
        $details = implode("\n", array_map(fn ($f) => $f->detail, $v));
        foreach (['InData::inline', 'InData::varargs', 'InData::constant', 'InData::fq', 'InData::second'] as $where) {
            $this->assertStringContainsString($where, $details);
        }
    }

    public function test_an_in_over_enum_cases_or_a_dynamic_argument_is_counted_never_warned(): void
    {
        $file = $this->php('InPass.php', <<<'PHP'
            <?php
            namespace Acme;
            use Spatie\LaravelData\Attributes\Validation\In;
            use Spatie\TypeScriptTransformer\Attributes\TypeScript;
            #[TypeScript]
            class InPass {
                public function __construct(
                    #[In(...Flavor::cases())] public ?string $enumCases,
                    #[In($dynamic)] public string $dyn,
                ) {}
            }
            enum Flavor: string { case A = 'a'; case B = 'b'; }
            PHP);

        $census = $this->census([$file]);
        $this->assertSame([], $this->findings([$file], ClosedVocabularyAudit::CHECK_VALIDATION_VOCABULARY));
        $this->assertStringContainsString('1 in-enum', $census);
        $this->assertStringContainsString('1 in-dynamic', $census);
    }

    /* ---------------- census + empty scan ---------------- */

    public function test_an_empty_scan_returns_an_inconclusive_finding_not_an_empty_list(): void
    {
        $findings = (new ClosedVocabularyAudit([]))->run();
        $this->assertCount(1, $findings);
        $this->assertSame(ClosedVocabularyAudit::CHECK_CENSUS, $findings[0]->check);
        $this->assertFalse($findings[0]->conclusive);
    }

    public function test_the_census_is_emitted_on_every_run_and_keeps_each_class_apart_including_mismatch_and_docblock(): void
    {
        $file = $this->php('All.php', <<<'PHP'
            <?php
            namespace Acme;
            use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
            use Spatie\TypeScriptTransformer\Attributes\TypeScript;
            #[TypeScript]
            class All {
                /** @param 'ltr'|'rtl' $dir */
                public function __construct(
                    #[LiteralTypeScriptType("'a' | 'b'")] public string $vocab,
                    #[LiteralTypeScriptType("{ x: string }")] public array $obj,
                    #[LiteralTypeScriptType("'k'")] public string $mismatch = 'other',
                    #[LiteralTypeScriptType("'k'")] public string $clean = 'k',
                    #[LiteralTypeScriptType("3")] public int $ver,
                    #[LiteralTypeScriptType("list<int>")] public array $struct,
                    #[LiteralTypeScriptType("unknown")] public mixed $inf,
                    public string $dir,
                ) {}
            }
            PHP);

        $census = $this->census([$file]);
        foreach ([
            '1 discriminator,', '1 version', '1 structural', '1 inference-override',
            '1 literal-vocabulary', '1 inline-object', '1 discriminator-mismatch', '1 docblock-vocabulary',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $census);
        }
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

    /** @return list<\Rushing\Doctor\Finding> */
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

    private function dataClass(string $name, string $class, string $members, string $tail = ''): string
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
                ) {}{$tail}
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
