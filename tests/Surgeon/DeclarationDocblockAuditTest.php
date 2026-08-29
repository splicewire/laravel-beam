<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Surgeon\DeclarationDocblockAudit;

/**
 * The docblock-vs-declaration audit. Pure unit test — no Laravel boot, no testbench provider list, so it
 * cannot fall into the estate's own auto-discovery trap: the audit is constructed with its file set
 * directly, exactly as the sweep's binding does.
 *
 * **The acceptance case is a fixture, not the live file.** `Schemastud\Frame\Registry\RouteContextEntry`
 * is what this audit was built to find and the only estate-wide finding it produces today — but pointing
 * a test at a neighbouring package's live source means the test starts failing the moment somebody fixes
 * that file, which is the outcome the audit exists to cause. The fixture reproduces both defects
 * byte-for-byte in shape, and `test_it_reports_both_route_context_entry_cases_from_the_live_files` reads
 * the live pair when they are on disk and skips when they are not.
 */
class DeclarationDocblockAuditTest extends TestCase
{
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->rrmdir($root);
        }
        $this->roots = [];

        parent::tearDown();
    }

    /* ---------------- check A — the phantom parameter ---------------- */

    public function test_it_fails_a_constructor_docblock_naming_a_parameter_that_does_not_exist(): void
    {
        $file = $this->php('Entry.php', <<<'PHP'
            <?php

            namespace Acme\Frame;

            class RouteContextEntry
            {
                /**
                 * @param  string  $mounts  what renders: list, or a serialized widget (see $widget/$redirect)
                 * @param  string|null  $widget  the registered widget name
                 */
                public function __construct(
                    public string $mounts = 'list',
                    public ?string $widget = null,
                ) {}
            }
            PHP);

        $findings = $this->findings([$file], check: DeclarationDocblockAudit::CHECK_PHANTOM);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString('$redirect', $findings[0]->detail);
        $this->assertStringContainsString('Acme\Frame\RouteContextEntry', $findings[0]->detail);
    }

    public function test_a_docblock_that_names_only_real_parameters_is_clean(): void
    {
        $file = $this->php('Clean.php', <<<'PHP'
            <?php

            namespace Acme;

            class Clean
            {
                /**
                 * @param  string  $name  see $slug for the derived form
                 * @param  string  $slug  the slug
                 */
                public function __construct(public string $name, public string $slug) {}
            }
            PHP);

        $this->assertSame([], $this->findings([$file], check: DeclarationDocblockAudit::CHECK_PHANTOM));
    }

    /**
     * The three exclusions that took the estate-wide count from 1,098 to 1, each of which was a real false
     * positive before it was added. The closure-signature one is the subtle one: `$model` there names a
     * parameter of the CALLABLE, and nothing about the constructor.
     */
    public function test_it_ignores_class_docblocks_code_spans_and_closure_signatures(): void
    {
        $file = $this->php('Noise.php', <<<'PHP'
            <?php

            namespace Acme;

            /**
             * Usage: $repository->find($id) returns the row, and $request carries the actor.
             */
            class Noise
            {
                /**
                 * @param  string  $stem  the `$id` stem, quoted as "$ref" elsewhere
                 * @param  (Closure(mixed $model, mixed $actor): void)|null  $prepare  the before-write hook
                 */
                public function __construct(public string $stem, public $prepare = null) {}
            }
            PHP);

        $this->assertSame([], $this->findings([$file], check: DeclarationDocblockAudit::CHECK_PHANTOM));
    }

    public function test_a_promoted_property_and_a_plain_property_both_count_as_declared(): void
    {
        $file = $this->php('Mixed.php', <<<'PHP'
            <?php

            namespace Acme;

            class MixedDeclaration
            {
                public ?string $cached = null;

                /**
                 * @param  string  $name  paired with $cached, and never with $missing
                 */
                public function __construct(public string $name) {}
            }
            PHP);

        $findings = $this->findings([$file], check: DeclarationDocblockAudit::CHECK_PHANTOM);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('$missing', $findings[0]->detail);
    }

    /**
     * A method's own parameters sit at the same class-brace depth a property does, and only the enclosing
     * parenthesis tells them apart. Reading them as properties would silence real phantoms by declaring
     * every method argument in the class.
     */
    public function test_a_method_parameter_is_not_mistaken_for_a_property(): void
    {
        $file = $this->php('Methods.php', <<<'PHP'
            <?php

            namespace Acme;

            class Methods
            {
                /**
                 * @param  string  $name  and also $handler, which is a method argument elsewhere
                 */
                public function __construct(public string $name) {}

                public function bind(string $handler): void {}
            }
            PHP);

        $findings = $this->findings([$file], check: DeclarationDocblockAudit::CHECK_PHANTOM);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('$handler', $findings[0]->detail);
    }

    /* ---------------- check B — wider than the TypeScript counterpart ---------------- */

    public function test_it_warns_when_php_declares_string_against_a_closed_typescript_union(): void
    {
        $php = $this->php('Route.php', <<<'PHP'
            <?php

            namespace Acme;

            use Spatie\TypeScriptTransformer\Attributes\TypeScript;

            #[TypeScript]
            class RouteContextEntry
            {
                public function __construct(
                    public string $mounts = 'list',
                    public ?string $widget = null,
                ) {}
            }
            PHP);

        $ts = $this->ts('types.ts', <<<'TS'
            export type RouteMounts = 'list' | 'edit' | 'detail' | 'widget' | 'redirect';

            export interface RouteContextEntry {
                // What renders.
                mounts: RouteMounts;
                widget?: string | null;
            }
            TS);

        $findings = $this->findings([$php], [dirname($ts)], DeclarationDocblockAudit::CHECK_TS_NARROWER);

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('$mounts', $findings[0]->detail);
        $this->assertStringContainsString("'redirect'", $findings[0]->detail);
    }

    /** A nullable PHP type against `string | null` is agreement, and the estate is full of it. */
    public function test_a_nullable_php_type_against_string_or_null_is_not_a_finding(): void
    {
        $php = $this->php('Nullable.php', <<<'PHP'
            <?php

            namespace Acme;

            use Spatie\TypeScriptTransformer\Attributes\TypeScript;

            #[TypeScript]
            class Nullable
            {
                public function __construct(public ?string $shell = null) {}
            }
            PHP);

        $ts = $this->ts('types.ts', <<<'TS'
            export interface Nullable {
                shell: string | null;
            }
            TS);

        $this->assertSame([], $this->findings([$php], [dirname($ts)], DeclarationDocblockAudit::CHECK_TS_NARROWER));
    }

    /**
     * With no TypeScript root there is no counterpart, and the check has NOT run. The census line is the
     * only thing standing between that and a report that reads as clean — this asserts it says so.
     */
    public function test_the_census_states_that_the_typescript_check_did_not_run(): void
    {
        $php = $this->php('Route.php', "<?php\n\nnamespace Acme;\n\nclass Route {}\n");

        $census = $this->findings([$php], [], DeclarationDocblockAudit::CHECK_CENSUS);

        $this->assertCount(1, $census);
        $this->assertSame(DoctorStatus::Pass, $census[0]->status);
        $this->assertStringContainsString('the TypeScript check did not run', $census[0]->detail);
    }

    /* ---------------- check C — the docblock enumerates cases the enum does not carry ---------------- */

    public function test_it_warns_when_a_docblock_enumerates_a_case_the_enum_does_not_declare(): void
    {
        $enum = $this->php('Mounts.php', <<<'PHP'
            <?php

            namespace Acme;

            enum Mounts: string
            {
                case List = 'list';
                case Edit = 'edit';
            }
            PHP);

        $class = $this->php('Leaf.php', <<<'PHP'
            <?php

            namespace Acme;

            class Leaf
            {
                /**
                 * @param  Mounts  $mounts  one of `list`, `edit` or `redirect`
                 */
                public function __construct(public Mounts $mounts) {}
            }
            PHP);

        $findings = $this->findings([$enum, $class], check: DeclarationDocblockAudit::CHECK_ENUM);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('`redirect`', $findings[0]->detail);
    }

    /**
     * The line must demonstrably be enumerating THAT enum. `php-popcorn`'s `?Build $build` docblock says
     * *"ships a runnable `.wasm` or source"* — a file extension in backticks, not a case — and the first
     * estate-wide run reported it. It names none of `Build`'s cases, so it is not an enumeration.
     */
    public function test_backticked_prose_that_names_no_case_of_the_enum_is_not_an_enumeration(): void
    {
        $enum = $this->php('Build.php', <<<'PHP'
            <?php

            namespace Acme;

            enum Build: string
            {
                case Prebuilt = 'prebuilt';
                case Source = 'source';
            }
            PHP);

        $class = $this->php('Manifest.php', <<<'PHP'
            <?php

            namespace Acme;

            class Manifest
            {
                /**
                 * @param  ?Build  $build  whether the bundle ships a runnable `.wasm` or source to compile
                 */
                public function __construct(public ?Build $build = null) {}
            }
            PHP);

        $this->assertSame([], $this->findings([$enum, $class], check: DeclarationDocblockAudit::CHECK_ENUM));
    }

    /** An enum this run never read is counted as unresolvable, never reported as agreeing. */
    public function test_an_unresolvable_enum_is_counted_in_the_census_rather_than_checked(): void
    {
        $class = $this->php('Leaf.php', <<<'PHP'
            <?php

            namespace Acme;

            class Leaf
            {
                /**
                 * @param  Mounts  $mounts  one of `list`, `edit` or `redirect`
                 */
                public function __construct(public Mounts $mounts) {}
            }
            PHP);

        $this->assertSame([], $this->findings([$class], check: DeclarationDocblockAudit::CHECK_ENUM));

        $census = $this->findings([$class], check: DeclarationDocblockAudit::CHECK_CENSUS);
        $this->assertStringContainsString('1 docblock enum reference(s) were unresolvable', $census[0]->detail);
    }

    /* ---------------- the acceptance case, read from live source ---------------- */

    /**
     * The brief's acceptance test: *if it does not report the two `RouteContextEntry` cases, it is not the
     * audit.* Skipped rather than failed when the pair is not on disk, so the package suite stays runnable
     * from a checkout that does not carry the whole estate.
     */
    public function test_it_reports_both_route_context_entry_cases_from_the_live_files(): void
    {
        $php = getenv('HOME').'/Workspaces/php/packages/schemastud/laravel-frame/src/Registry/RouteContextEntry.php';
        $ts = getenv('HOME').'/Workspaces/js/packages/schemastud/frame/src';

        if (! is_file($php) || ! is_dir($ts)) {
            $this->markTestSkipped('The laravel-frame / frame pair is not present in this checkout.');
        }

        $findings = (new DeclarationDocblockAudit([$php], [$ts]))->run();
        $byCheck = [];
        foreach ($findings as $finding) {
            $byCheck[$finding->check][] = $finding->detail;
        }

        $this->assertArrayHasKey(DeclarationDocblockAudit::CHECK_PHANTOM, $byCheck, 'the $redirect case');
        $this->assertStringContainsString('$redirect', $byCheck[DeclarationDocblockAudit::CHECK_PHANTOM][0]);

        $this->assertArrayHasKey(DeclarationDocblockAudit::CHECK_TS_NARROWER, $byCheck, 'the string-vs-closed-union case');
        $this->assertStringContainsString('$mounts', $byCheck[DeclarationDocblockAudit::CHECK_TS_NARROWER][0]);
    }

    /* ---------------- the JS-sibling derivation ---------------- */

    public function test_the_javascript_sibling_is_derived_by_shape_and_probed_before_it_is_returned(): void
    {
        $root = $this->tmp('sibling');
        $this->roots[] = $root;

        mkdir($root.'/php/packages/schemastud/laravel-frame/src', 0777, true);
        mkdir($root.'/js/packages/schemastud/frame/src', 0777, true);

        $this->assertSame(
            realpath($root.'/js/packages/schemastud/frame/src'),
            DeclarationDocblockAudit::javascriptSibling(realpath($root.'/php/packages/schemastud/laravel-frame/src')),
        );

        // A package with no JS half resolves to null rather than to a directory that is not there.
        mkdir($root.'/php/packages/splicewire/laravel-beam/src', 0777, true);
        $this->assertNull(DeclarationDocblockAudit::javascriptSibling(realpath($root.'/php/packages/splicewire/laravel-beam/src')));

        $this->assertNull(DeclarationDocblockAudit::javascriptSibling('/not/a/packages/layout'));
    }

    /* ---------------- plumbing ---------------- */

    /**
     * @param  list<string>  $files
     * @param  list<string>  $typescriptRoots
     * @return list<Finding>
     */
    private function findings(array $files, array $typescriptRoots = [], ?string $check = null): array
    {
        $findings = (new DeclarationDocblockAudit($files, $typescriptRoots))->run();

        if ($check === null) {
            return $findings;
        }

        return array_values(array_filter($findings, fn ($finding) => $finding->check === $check));
    }

    private function php(string $name, string $contents): string
    {
        $root = $this->tmp('declaration-docblock');
        $this->roots[] = $root;

        $path = $root.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function ts(string $name, string $contents): string
    {
        return $this->php($name, $contents);
    }

    private function tmp(string $prefix): string
    {
        // pid-keyed: this estate's suites share one temp dir, and a fixed scratch name once accounted for
        // 29 of a 35-failure delta between two concurrent sessions.
        $root = sys_get_temp_dir().'/'.$prefix.'-'.getmypid().'-'.uniqid();
        mkdir($root, 0777, true);

        return $root;
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
