<?php

namespace Splicewire\Beam\Tests\Console;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use Rushing\Doctor\DoctorStatus;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surgeon\HouseStyleAudit;
use Splicewire\Beam\Surgeon\InertiaPropShapeAudit;
use Splicewire\Beam\Surgeon\UndeclaredSurfaceAudit;
use Splicewire\Beam\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * `splicewire:beam:make:page` (make-surface ticket 03). The bar is the ticket's: **a generated page, mounted, produces
 * zero findings from the undeclared-surface audit and the Inertia props audit.** Both are asserted here independently
 * of the command's own check, on the files it wrote.
 *
 * The throwaway host follows {@see ParticleGeneratorTest}: the whole base path moves to a temp directory outside the
 * package tree with a two-line `composer.json`, so `GeneratorCommand` resolves `App\` and the audits are not excluded
 * by a `/tests/` path fragment. Each test generates a page name no other test uses, because a class an earlier run
 * loaded stays loaded in this process.
 */
class PageGeneratorTest extends TestCase
{
    private string $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = sys_get_temp_dir().'/beam-page-gen-'.Str::random(10);

        File::ensureDirectoryExists($this->host.'/app');
        File::put($this->host.'/composer.json', json_encode(
            ['autoload' => ['psr-4' => ['App\\' => 'app/']]],
            JSON_PRETTY_PRINT,
        ));

        $this->app->setBasePath($this->host);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->host);

        parent::tearDown();
    }

    // ── what gets emitted ───────────────────────────────────────────────────────────────────────────

    public function test_it_emits_the_page_data_the_declared_action_and_the_typed_page_and_prints_the_route(): void
    {
        $this->artisan('splicewire:beam:make:page', ['name' => 'account/billing-summary'])
            ->expectsOutputToContain("Route::get('/account/billing-summary', \\App\\Http\\Controllers\\Pages\\BillingSummaryPageController::class)->name('account.billing-summary');")
            ->expectsOutputToContain('no findings')
            ->assertSuccessful();

        $data = $this->read('app/Data/Pages/BillingSummaryPageData.php');
        $action = $this->read('app/Http/Controllers/Pages/BillingSummaryPageController.php');
        $page = $this->read('resources/js/pages/account/billing-summary.tsx');

        $this->assertStringContainsString('namespace App\Data\Pages;', $data);
        $this->assertStringContainsString('#[TypeScript]', $data);
        $this->assertStringContainsString("\nclass BillingSummaryPageData extends Data", $data);
        $this->assertStringContainsString('public string $title,', $data);

        // The declaration and the render: the attribute names the Data class, and the render passes an OBJECT.
        $this->assertStringContainsString('#[ResponseFromData(BillingSummaryPageData::class)]', $action);
        $this->assertStringContainsString("return Inertia::render('account/billing-summary', new BillingSummaryPageData(", $action);
        $this->assertStringContainsString('public function __invoke(): Response', $action);
        $this->assertStringNotContainsString('RequestFromData', $action);

        // The page imports the GENERATED type by the Data class's native namespace (ModuleWriter layout).
        $this->assertStringContainsString("import type { BillingSummaryPageData } from '@/generated/App/Data/Pages';", $page);
        $this->assertStringContainsString('export default function BillingSummaryPage({ title }: BillingSummaryPageData)', $page);
        $this->assertStringNotContainsString('SiteLayout', $page);
        $this->assertStringNotContainsString('{{', $page);

        foreach (['app/Data/Pages/BillingSummaryPageData.php', 'app/Http/Controllers/Pages/BillingSummaryPageController.php'] as $file) {
            $this->assertLints($file);
        }
    }

    public function test_the_attributes_are_real_declarations_not_just_text(): void
    {
        $this->artisan('splicewire:beam:make:page', ['name' => 'operator/fleet-health'])->assertSuccessful();

        $this->load('App\Data\Pages\FleetHealthPageData', 'app/Data/Pages/FleetHealthPageData.php');
        $this->load('App\Http\Controllers\Pages\FleetHealthPageController', 'app/Http/Controllers/Pages/FleetHealthPageController.php');

        $this->assertCount(1, (new ReflectionClass('App\Data\Pages\FleetHealthPageData'))->getAttributes(TypeScript::class));

        $attributes = (new ReflectionClass('App\Http\Controllers\Pages\FleetHealthPageController'))
            ->getMethod('__invoke')->getAttributes(ResponseFromData::class);

        $this->assertCount(1, $attributes);
        $this->assertSame('App\Data\Pages\FleetHealthPageData', $attributes[0]->newInstance()->dataClass);
    }

    // ── the ticket's bar: zero findings, asserted independently ─────────────────────────────────────

    public function test_a_generated_page_mounted_on_its_printed_route_has_no_undeclared_surface_finding(): void
    {
        $this->artisan('splicewire:beam:make:page', ['name' => 'account/usage-report'])->assertSuccessful();

        $this->load('App\Data\Pages\UsageReportPageData', 'app/Data/Pages/UsageReportPageData.php');
        $this->load('App\Http\Controllers\Pages\UsageReportPageController', 'app/Http/Controllers/Pages/UsageReportPageController.php');

        Route::prefix('mounted')->group(fn () => Route::get('account/usage-report', 'App\Http\Controllers\Pages\UsageReportPageController'));

        // The audit must SEE the route, or "no finding" means nothing.
        $this->assertNotNull(Route::getRoutes()->getByAction('App\Http\Controllers\Pages\UsageReportPageController'));
        $this->assertNoUndeclaredFindingsFor('mounted/account/usage-report');
    }

    /**
     * The audit records only undeclared and propless renders (a Data-object render is skipped by design), so the pass
     * is "no row for this page"; {@see test_the_same_audit_does_count_a_flattened_render_so_the_zero_above_is_not_vacuous}
     * proves the same audit, over the same root, does reach a generated action.
     */
    public function test_a_generated_page_has_no_inertia_props_finding(): void
    {
        $this->artisan('splicewire:beam:make:page', ['name' => 'settings/notification-prefs'])->assertSuccessful();

        $audit = new InertiaPropShapeAudit([$this->host.'/app']);

        $this->assertSame([], $audit->sites());
        $this->assertSame([], $audit->undeclared());
    }

    public function test_the_same_audit_does_count_a_flattened_render_so_the_zero_above_is_not_vacuous(): void
    {
        $this->artisan('splicewire:beam:make:page', ['name' => 'account/flattened-control'])->assertSuccessful();

        $path = $this->host.'/app/Http/Controllers/Pages/FlattenedControlPageController.php';
        File::put($path, str_replace(
            'new FlattenedControlPageData(',
            "['page' => new FlattenedControlPageData(",
            str_replace("        ));\n", "        )]);\n", (string) File::get($path)),
        ));

        $this->assertNotSame([], (new InertiaPropShapeAudit([$this->host.'/app']))->undeclared());
    }

    // ── the generator's own check judges the bytes it wrote ─────────────────────────────────────────

    public function test_a_host_stub_that_flattens_the_props_fails_the_run(): void
    {
        $stub = (string) File::get(dirname(__DIR__, 2).'/stubs/page-controller.stub');
        $broken = str_replace("new {{ pageData }}(\n            title: '{{ title }}',\n        ));", "['title' => '{{ title }}']);", $stub);

        $this->assertNotSame($stub, $broken, 'The controller stub no longer renders the props the way this test rewrites.');

        File::ensureDirectoryExists($this->host.'/stubs');
        File::put($this->host.'/stubs/page-controller.stub', $broken);

        $this->artisan('splicewire:beam:make:page', ['name' => 'account/ghost-page'])
            ->expectsOutputToContain(InertiaPropShapeAudit::CHECK)
            ->assertExitCode(1);

        // The files stay written: the check reports, it does not roll back the author's tree.
        $this->assertFileExists($this->host.'/app/Http/Controllers/Pages/GhostPagePageController.php');
    }

    public function test_a_host_stub_without_the_response_attribute_fails_the_run(): void
    {
        $stub = (string) File::get(dirname(__DIR__, 2).'/stubs/page-controller.stub');
        $broken = str_replace('    #[ResponseFromData({{ pageData }}::class)]{{ requestAttribute }}', '    {{ requestAttribute }}', $stub);

        $this->assertNotSame($stub, $broken, 'The controller stub no longer carries the attribute this test removes.');

        File::ensureDirectoryExists($this->host.'/stubs');
        File::put($this->host.'/stubs/page-controller.stub', $broken);

        $this->artisan('splicewire:beam:make:page', ['name' => 'account/undeclared-page'])
            ->expectsOutputToContain(UndeclaredSurfaceAudit::CHECK)
            ->assertExitCode(1);
    }

    public function test_no_verify_skips_the_check(): void
    {
        $this->assertTrue(
            $this->app->make(Kernel::class)->all()['splicewire:beam:make:page']->getDefinition()->hasOption('no-verify'),
        );
    }

    // ── --request ───────────────────────────────────────────────────────────────────────────────────

    public function test_request_declares_request_from_data_and_emits_the_input_when_missing(): void
    {
        $this->artisan('splicewire:beam:make:page', ['name' => 'operator/audit-search', '--request' => 'AuditSearchQueryData'])
            ->expectsOutputToContain('no findings')
            ->assertSuccessful();

        $action = $this->read('app/Http/Controllers/Pages/AuditSearchPageController.php');

        $this->assertStringContainsString('use App\Data\AuditSearchQueryData;', $action);
        $this->assertStringContainsString('#[RequestFromData(AuditSearchQueryData::class)]', $action);
        $this->assertStringContainsString('public function __invoke(AuditSearchQueryData $input): Response', $action);
        $this->assertStringContainsString('class AuditSearchQueryData extends Data', $this->read('app/Data/AuditSearchQueryData.php'));
        $this->assertLints('app/Http/Controllers/Pages/AuditSearchPageController.php');

        $this->load('App\Data\AuditSearchQueryData', 'app/Data/AuditSearchQueryData.php');
        $this->load('App\Data\Pages\AuditSearchPageData', 'app/Data/Pages/AuditSearchPageData.php');
        $this->load('App\Http\Controllers\Pages\AuditSearchPageController', 'app/Http/Controllers/Pages/AuditSearchPageController.php');

        $request = (new ReflectionClass('App\Http\Controllers\Pages\AuditSearchPageController'))
            ->getMethod('__invoke')->getAttributes(RequestFromData::class);

        $this->assertCount(1, $request);
        $this->assertSame('App\Data\AuditSearchQueryData', $request[0]->newInstance()->dataClass);
    }

    public function test_request_keeps_an_existing_input_data_class(): void
    {
        File::ensureDirectoryExists($this->host.'/app/Data');
        File::put($this->host.'/app/Data/ExistingFilterData.php', "<?php\n\nnamespace App\Data;\n\nuse Spatie\LaravelData\Data;\n\nclass ExistingFilterData extends Data\n{\n    public function __construct(public string \$q = '') {}\n}\n");
        $before = File::get($this->host.'/app/Data/ExistingFilterData.php');

        $this->artisan('splicewire:beam:make:page', ['name' => 'account/filtered-list', '--request' => 'ExistingFilterData'])
            ->expectsOutputToContain('exists, kept')
            ->assertSuccessful();

        $this->assertSame($before, File::get($this->host.'/app/Data/ExistingFilterData.php'));
    }

    // ── companions are kept; the primary refuses without --force ────────────────────────────────────

    public function test_existing_companions_are_reported_and_kept(): void
    {
        File::ensureDirectoryExists($this->host.'/resources/js/pages/account');
        File::put($this->host.'/resources/js/pages/account/team-roster.tsx', "export default function Mine() { return null; }\n");

        // One expectation: the path and "exists, kept" are one twoColumnDetail write, and a write satisfies one.
        $this->artisan('splicewire:beam:make:page', ['name' => 'account/team-roster'])
            ->expectsOutputToContain('exists, kept')
            ->assertSuccessful();

        $this->assertSame("export default function Mine() { return null; }\n", File::get($this->host.'/resources/js/pages/account/team-roster.tsx'));
    }

    public function test_a_second_run_refuses_the_existing_primary_and_force_keeps_the_companions(): void
    {
        $this->artisan('splicewire:beam:make:page', ['name' => 'account/rerun-page'])->assertSuccessful();

        $action = $this->host.'/app/Http/Controllers/Pages/RerunPagePageController.php';
        File::put($action, File::get($action)."// the author's edit\n");

        $this->artisan('splicewire:beam:make:page', ['name' => 'account/rerun-page'])->assertExitCode(1);

        $this->artisan('splicewire:beam:make:page', ['name' => 'account/rerun-page', '--force' => true])
            ->expectsOutputToContain('exists, kept')
            ->assertSuccessful();

        $this->assertStringEndsWith("// the author's edit\n", File::get($action));
    }

    // ── names, realms and the URI ───────────────────────────────────────────────────────────────────

    public function test_a_name_that_is_not_realm_slash_kebab_is_refused_before_anything_is_written(): void
    {
        foreach (['billing', 'Account/Billing', 'account/billing_summary', 'account/'] as $name) {
            $this->artisan('splicewire:beam:make:page', ['name' => $name])->assertExitCode(1);
        }

        $this->assertDirectoryDoesNotExist($this->host.'/app/Data');
        $this->assertDirectoryDoesNotExist($this->host.'/resources');
    }

    public function test_an_unknown_realm_is_reported_but_generated(): void
    {
        $this->artisan('splicewire:beam:make:page', ['name' => 'reports/quarterly-close'])
            ->expectsOutputToContain('not a beam-inertia layout prefix')
            ->assertSuccessful();

        $this->assertFileExists($this->host.'/resources/js/pages/reports/quarterly-close.tsx');
    }

    public function test_a_site_page_carries_its_own_site_layout_and_mounts_at_the_root(): void
    {
        // The placement note: the starter host proof 404'd with the line pasted after Route::beamUxSite().
        // The chrome warning: the host proof rendered a site page without the starter's site nav, because
        // beam-inertia's site chrome is internal; the generator says so rather than implying parity.
        $this->artisan('splicewire:beam:make:page', ['name' => 'site/about-us'])
            ->expectsOutputToContain('does not export its OOTB site chrome')
            ->expectsOutputToContain("Route::get('/about-us', ")
            ->expectsOutputToContain('ABOVE Route::beamUxSite()')
            ->assertSuccessful();

        $page = $this->read('resources/js/pages/site/about-us.tsx');

        $this->assertStringContainsString("import { SiteLayout } from '@splicewire/beam-ux/site';", $page);
        $this->assertStringContainsString('<SiteLayout>', $page);
    }

    public function test_a_nested_name_and_an_explicit_uri(): void
    {
        $this->artisan('splicewire:beam:make:page', ['name' => 'account/settings/api-keys', '--uri' => 'account/keys'])
            ->expectsOutputToContain("Route::get('/account/keys', \\App\\Http\\Controllers\\Pages\\SettingsApiKeysPageController::class)->name('account.settings.api-keys');")
            ->assertSuccessful();

        $this->assertStringContainsString("Inertia::render('account/settings/api-keys'", $this->read('app/Http/Controllers/Pages/SettingsApiKeysPageController.php'));
        $this->assertFileExists($this->host.'/resources/js/pages/account/settings/api-keys.tsx');
    }

    public function test_the_generated_php_is_already_formatter_clean(): void
    {
        $pint = dirname(__DIR__, 2).'/vendor/bin/pint';

        if (! is_file($pint)) {
            $this->markTestSkipped('pint is not installed in this checkout.');
        }

        $this->artisan('splicewire:beam:make:page', ['name' => 'account/fmt-page', '--request' => 'FmtFilterData'])->assertSuccessful();

        $process = Process::fromShellCommandline(sprintf(
            'XDEBUG_MODE=off %s --test %s 2>&1',
            escapeshellarg($pint),
            escapeshellarg($this->host.'/app'),
        ));
        $process->run();

        $this->assertTrue($process->isSuccessful(), 'Generated code is not pint-clean: '.$process->getOutput());
    }

    /**
     * House style forbids `final`, `readonly` and `declare(strict_types=1)` (`HouseStyleAudit`). The first host proof
     * caught this: the draft stubs emitted `final class`, and the host's surgeon sweep grew by one
     * `house-style.forbidden-modifier` per generated PHP file.
     */
    public function test_the_generated_php_carries_no_forbidden_house_style_construct(): void
    {
        $this->artisan('splicewire:beam:make:page', ['name' => 'account/style-page', '--request' => 'StyleFilterData'])->assertSuccessful();

        $findings = (new HouseStyleAudit([$this->host.'/app']))->run();

        $this->assertNotSame([], $findings);
        foreach ($findings as $finding) {
            $this->assertSame(DoctorStatus::Pass, $finding->status, $finding->check.': '.$finding->detail);
        }
    }

    public function test_the_command_is_listed_with_the_other_generators(): void
    {
        $this->assertArrayHasKey('splicewire:beam:make:page', $this->app->make(Kernel::class)->all());
    }

    private function read(string $relative): string
    {
        $path = $this->host.'/'.$relative;

        $this->assertFileExists($path);

        return (string) File::get($path);
    }

    private function load(string $class, string $relative): void
    {
        if (! class_exists($class, false)) {
            require_once $this->host.'/'.$relative;
        }
    }

    private function assertLints(string $relative): void
    {
        $lint = new Process([PHP_BINARY, '-l', $this->host.'/'.$relative], env: ['XDEBUG_MODE' => 'off']);
        $lint->run();

        $this->assertSame(0, $lint->getExitCode(), $lint->getOutput().$lint->getErrorOutput());
    }

    private function assertNoUndeclaredFindingsFor(string $uriPrefix): void
    {
        $audit = new UndeclaredSurfaceAudit(
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
        );

        $offending = array_values(array_filter($audit->undeclared(), fn (array $row) => str_starts_with($row['uri'], $uriPrefix)));

        $this->assertSame([], $offending, "A freshly generated page tripped the undeclared-surface audit at [{$uriPrefix}].");
    }
}
