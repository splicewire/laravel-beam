<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Surgeon\SdkHookMigrationAudit;
use Splicewire\Beam\Surgeon\SdkReturnsCoverageAudit;

/**
 * Ticket 25 (`/grill-me` follow-on to {@see SdkHookMigrationAudit}). `suggestFor()`
 * is the pure finding-shaping core (plain tiered rows, no disk/AST — mirrors the sibling audits' pure-core
 * convention); `classify()` is the AST-touching half, exercised here against real PHP source strings
 * written to temp files so it's still a fast, hermetic unit test — no app boot, no live routes.
 */
class SdkReturnsCoverageAuditTest extends TestCase
{
    private function audit(): SdkReturnsCoverageAudit
    {
        return new SdkReturnsCoverageAudit([]);
    }

    public function test_high_medium_low_tiers_each_produce_one_warn_finding(): void
    {
        $findings = $this->audit()->suggestFor([
            ['routeName' => 'a.index', 'uri' => 'a', 'tier' => 'high'],
            ['routeName' => 'b.index', 'uri' => 'b', 'tier' => 'medium'],
            ['routeName' => null, 'uri' => 'c', 'tier' => 'low'],
        ]);

        $this->assertCount(3, $findings);
        foreach ($findings as $finding) {
            $this->assertSame(DoctorStatus::Warn, $finding->status);
            $this->assertSame('sdk.returns-coverage', $finding->check);
        }
        $this->assertStringContainsString('a.index', $findings[0]->detail);
        $this->assertStringContainsString('tier: high', $findings[0]->detail);
        $this->assertStringContainsString('tier: medium', $findings[1]->detail);
        // No route name — falls back to the uri in the detail line.
        $this->assertStringContainsString('c', $findings[2]->detail);
        $this->assertStringContainsString('tier: low', $findings[2]->detail);
    }

    public function test_a_null_tier_produces_no_finding(): void
    {
        $findings = $this->audit()->suggestFor([
            ['routeName' => 'filtered.index', 'uri' => 'filtered', 'tier' => null],
        ]);

        $this->assertSame([], $findings);
    }

    public function test_an_empty_row_set_produces_no_findings(): void
    {
        $this->assertSame([], $this->audit()->suggestFor([]));
    }

    public function test_classify_tiers_high_when_response_from_data_present_and_unfiltered(): void
    {
        $file = $this->writeFixture(<<<'PHP'
            <?php
            use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
            class WidgetController {
                #[ResponseFromData(WidgetData::class)]
                public function show($id) {
                    return WidgetData::from(Widget::findOrFail($id));
                }
            }
            PHP);

        $rows = $this->audit()->classify([
            ['routeName' => 'widgets.show', 'uri' => 'widgets/{id}', 'controllerFile' => $file, 'controllerClass' => null, 'actionMethod' => 'show'],
        ]);

        $this->assertSame('high', $rows[0]['tier']);
    }

    public function test_classify_tiers_medium_on_bare_dto_construction(): void
    {
        $file = $this->writeFixture(<<<'PHP'
            <?php
            class WidgetController {
                public function show($id) {
                    return WidgetData::fromModel(Widget::findOrFail($id));
                }
            }
            PHP);

        $rows = $this->audit()->classify([
            ['routeName' => 'widgets.show', 'uri' => 'widgets/{id}', 'controllerFile' => $file, 'controllerClass' => null, 'actionMethod' => 'show'],
        ]);

        $this->assertSame('medium', $rows[0]['tier']);
    }

    public function test_classify_excludes_a_route_with_the_filter_idiom_regardless_of_dto_construction(): void
    {
        $file = $this->writeFixture(<<<'PHP'
            <?php
            class WidgetController {
                public function index() {
                    $widgets = DataFilter::query('widgets')->apply(request());
                    return WidgetData::collect($widgets);
                }
            }
            PHP);

        $rows = $this->audit()->classify([
            ['routeName' => 'widgets.index', 'uri' => 'widgets', 'controllerFile' => $file, 'controllerClass' => null, 'actionMethod' => 'index'],
        ]);

        $this->assertNull($rows[0]['tier']);
    }

    public function test_classify_tiers_low_when_no_dto_signal_found(): void
    {
        $file = $this->writeFixture(<<<'PHP'
            <?php
            class WidgetController {
                public function index() {
                    return response()->json(['ok' => true]);
                }
            }
            PHP);

        $rows = $this->audit()->classify([
            ['routeName' => 'widgets.index', 'uri' => 'widgets', 'controllerFile' => $file, 'controllerClass' => null, 'actionMethod' => 'index'],
        ]);

        $this->assertSame('low', $rows[0]['tier']);
    }

    public function test_classify_tiers_low_when_the_action_cannot_be_resolved(): void
    {
        $rows = $this->audit()->classify([
            ['routeName' => 'ghost.index', 'uri' => 'ghost', 'controllerFile' => null, 'controllerClass' => null, 'actionMethod' => null],
        ]);

        $this->assertSame('low', $rows[0]['tier']);
    }

    public function test_classify_follows_a_same_class_method_call_one_level_deep(): void
    {
        $file = $this->requireFixtureClass('RccSameClass', <<<'PHP'
            <?php
            class RccSameClassController {
                public function index() {
                    return $this->build();
                }
                protected function build() {
                    return WidgetData::fromModel(Widget::find(1));
                }
            }
            PHP);

        $rows = $this->audit()->classify([
            ['routeName' => 'rcc-same.index', 'uri' => 'rcc-same', 'controllerFile' => $file, 'controllerClass' => \RccSameClassController::class, 'actionMethod' => 'index'],
        ]);

        $this->assertSame('medium', $rows[0]['tier']);
    }

    public function test_classify_follows_a_trait_provided_method_call_one_level_deep(): void
    {
        $this->requireFixtureClass('RccTraitTrait', <<<'PHP'
            <?php
            trait RccBuildsWidgetTrait {
                protected function build() {
                    return WidgetData::fromModel(Widget::find(1));
                }
            }
            PHP);

        $file = $this->requireFixtureClass('RccTraitClass', <<<'PHP'
            <?php
            class RccTraitController {
                use RccBuildsWidgetTrait;
                public function index() {
                    return $this->build();
                }
            }
            PHP);

        $rows = $this->audit()->classify([
            ['routeName' => 'rcc-trait.index', 'uri' => 'rcc-trait', 'controllerFile' => $file, 'controllerClass' => \RccTraitController::class, 'actionMethod' => 'index'],
        ]);

        $this->assertSame('medium', $rows[0]['tier']);
    }

    public function test_classify_follows_an_injected_service_method_call_one_level_deep(): void
    {
        $this->requireFixtureClass('RccService', <<<'PHP'
            <?php
            class RccWidgetService {
                public function fetch() {
                    return WidgetData::fromModel(Widget::find(1));
                }
            }
            PHP);

        $file = $this->requireFixtureClass('RccServiceController', <<<'PHP'
            <?php
            class RccServiceController {
                public function __construct(protected RccWidgetService $widgets) {}
                public function index() {
                    return $this->widgets->fetch();
                }
            }
            PHP);

        $rows = $this->audit()->classify([
            ['routeName' => 'rcc-service.index', 'uri' => 'rcc-service', 'controllerFile' => $file, 'controllerClass' => \RccServiceController::class, 'actionMethod' => 'index'],
        ]);

        $this->assertSame('medium', $rows[0]['tier']);
    }

    public function test_classify_stays_low_when_the_call_chain_does_not_resolve(): void
    {
        $file = $this->requireFixtureClass('RccUnresolvable', <<<'PHP'
            <?php
            class RccUnresolvableController {
                public function index() {
                    $helper = new SomeLocalHelper();

                    return $helper->compute();
                }
            }
            PHP);

        $rows = $this->audit()->classify([
            ['routeName' => 'rcc-unresolvable.index', 'uri' => 'rcc-unresolvable', 'controllerFile' => $file, 'controllerClass' => \RccUnresolvableController::class, 'actionMethod' => 'index'],
        ]);

        $this->assertSame('low', $rows[0]['tier']);
    }

    // --- ticket 26: suggestOperations() / apply-half tests ------------------------------------------

    public function test_suggest_operations_produces_a_fixable_literal_rewrite_for_a_high_tier_route(): void
    {
        $controllerFile = $this->writeFixture(<<<'PHP'
            <?php
            use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
            class T26HighController {
                #[ResponseFromData(T26WidgetData::class)]
                public function show($id) {
                    return T26WidgetData::from(Widget::findOrFail($id));
                }
            }
            PHP);
        $routeFile = $this->writeFixture(<<<'PHP'
            <?php
            use Illuminate\Support\Facades\Route;
            Route::get('widgets/{id}', [WidgetController::class, 'show'])->name('t26.widgets.show');
            PHP);

        $audit = new SdkReturnsCoverageAudit([
            ['routeName' => 't26.widgets.show', 'uri' => 'widgets/{id}', 'controllerFile' => $controllerFile, 'controllerClass' => null, 'actionMethod' => 'show'],
        ], [$routeFile => '']);

        $fixables = $audit->suggestOperations();
        $this->assertCount(1, $fixables);
        $this->assertTrue($fixables[0]->isFixable());
        $this->assertSame('literal-rewrite', $fixables[0]->suggestion->kind);
        $this->assertSame($routeFile, $fixables[0]->suggestion->payload['file']);
        $this->assertStringContainsString("->name('t26.widgets.show');", $fixables[0]->suggestion->payload['old']);
        $this->assertStringContainsString('->returns(\T26WidgetData::class);', $fixables[0]->suggestion->payload['new']);
    }

    public function test_suggest_operations_produces_a_fixable_many_true_rewrite_for_a_medium_tier_route(): void
    {
        $controllerFile = $this->writeFixture(<<<'PHP'
            <?php
            class T26MediumController {
                public function index() {
                    return T26WidgetData::collect(Widget::all());
                }
            }
            PHP);
        $routeFile = $this->writeFixture(<<<'PHP'
            <?php
            use Illuminate\Support\Facades\Route;
            Route::get('widgets', [WidgetController::class, 'index'])->name('t26.widgets.index');
            PHP);

        $audit = new SdkReturnsCoverageAudit([
            ['routeName' => 't26.widgets.index', 'uri' => 'widgets', 'controllerFile' => $controllerFile, 'controllerClass' => null, 'actionMethod' => 'index'],
        ], [$routeFile => '']);

        $suggestion = $audit->suggestOperations()[0]->suggestion;
        $this->assertNotNull($suggestion);
        $this->assertStringContainsString('->returns(\T26WidgetData::class, many: true);', $suggestion->payload['new']);
    }

    public function test_suggest_operations_stays_advisory_for_low_tier(): void
    {
        $controllerFile = $this->writeFixture(<<<'PHP'
            <?php
            class T26LowController {
                public function index() {
                    return response()->json(['ok' => true]);
                }
            }
            PHP);

        $audit = new SdkReturnsCoverageAudit([
            ['routeName' => 't26.low.index', 'uri' => 'low', 'controllerFile' => $controllerFile, 'controllerClass' => null, 'actionMethod' => 'index'],
        ]);

        $fixables = $audit->suggestOperations();
        $this->assertCount(1, $fixables);
        $this->assertFalse($fixables[0]->isFixable());
        $this->assertNull($fixables[0]->suggestion);
    }

    public function test_suggest_operations_stays_advisory_for_a_genuinely_ambiguous_multi_dto_body(): void
    {
        $controllerFile = $this->writeFixture(<<<'PHP'
            <?php
            class T26AmbiguousController {
                public function show($id) {
                    if ($id === 1) {
                        return T26WidgetData::from(Widget::find(1));
                    }
                    return T26GadgetData::from(Gadget::find($id));
                }
            }
            PHP);
        $routeFile = $this->writeFixture(<<<'PHP'
            <?php
            use Illuminate\Support\Facades\Route;
            Route::get('widgets/{id}', [WidgetController::class, 'show'])->name('t26.ambiguous.show');
            PHP);

        $audit = new SdkReturnsCoverageAudit([
            ['routeName' => 't26.ambiguous.show', 'uri' => 'widgets/{id}', 'controllerFile' => $controllerFile, 'controllerClass' => null, 'actionMethod' => 'show'],
        ], [$routeFile => '']);

        $fixables = $audit->suggestOperations();
        $this->assertFalse($fixables[0]->isFixable());
        $this->assertNull($fixables[0]->suggestion);
    }

    public function test_suggest_operations_stays_advisory_when_the_route_statement_cannot_be_located(): void
    {
        $controllerFile = $this->writeFixture(<<<'PHP'
            <?php
            class T26UnlocatableController {
                public function show($id) {
                    return T26WidgetData::from(Widget::findOrFail($id));
                }
            }
            PHP);

        // No route files supplied at all — the macro-registered / unresolvable case.
        $audit = new SdkReturnsCoverageAudit([
            ['routeName' => 't26.ghost.show', 'uri' => 'ghost/{id}', 'controllerFile' => $controllerFile, 'controllerClass' => null, 'actionMethod' => 'show'],
        ], []);

        $fixables = $audit->suggestOperations();
        $this->assertSame('medium', $this->tierOf($audit, $controllerFile, 'show', 't26.ghost.show'));
        $this->assertFalse($fixables[0]->isFixable());
        $this->assertNull($fixables[0]->suggestion);
    }

    /**
     * Regression test for a real bug caught live during ticket 26's own verification:
     * `TenantController::store()` constructs `EntitlementDenialData` only inside
     * `throw new EntitlementDeniedException(new EntitlementDenialData(...))` on an error path — a
     * whole-body DTO search wrongly picked that up as the route's response DTO.
     * {@see SdkReturnsCoverageAudit::returnExpressions()} scopes the search to `return` statement
     * expressions only, so a construction used to build a thrown exception (or any other side
     * statement) must never surface as the resolved `dtoClass`.
     */
    public function test_classify_ignores_dto_construction_used_to_build_a_thrown_exception(): void
    {
        $controllerFile = $this->writeFixture(<<<'PHP'
            <?php
            class T26ExceptionPathController {
                public function store() {
                    if (!$this->allowed()) {
                        throw new \RuntimeException('denied: '.T26DenialData::from(['reason' => 'x'])->reason);
                    }
                    return T26WidgetData::from(Widget::create());
                }
                private function allowed(): bool { return true; }
            }
            PHP);

        $rows = $this->audit()->classify([
            ['routeName' => 't26.exception-path.store', 'uri' => 'widgets', 'controllerFile' => $controllerFile, 'controllerClass' => null, 'actionMethod' => 'store'],
        ]);

        $this->assertSame('medium', $rows[0]['tier']);

        $detailed = $this->audit()->classifyDetailed([
            ['routeName' => 't26.exception-path.store', 'uri' => 'widgets', 'controllerFile' => $controllerFile, 'controllerClass' => null, 'actionMethod' => 'store'],
        ]);
        $this->assertSame('T26WidgetData', $detailed[0]['dtoClass']);
        $this->assertFalse($detailed[0]['ambiguous']);
    }

    /**
     * Regression test for a variable-source construction NOT inside the return statement at all
     * (assigned earlier, then referenced by name) — the real `WorkflowController::coverage()` shape
     * found live: a `$versions = ...->map(fn ($v) => new PerItemData(...))` assignment feeding a
     * `return ResponseBody::from(['data' => new OuterData(..., versions: $versions)])`. Only the
     * outer construction, actually reachable from the return expression, should resolve.
     */
    public function test_classify_ignores_dto_construction_from_an_earlier_assignment_statement(): void
    {
        $controllerFile = $this->writeFixture(<<<'PHP'
            <?php
            class T26EarlierAssignmentController {
                public function show() {
                    $versions = array_map(fn ($v) => new T26PerItemData($v), [1, 2, 3]);
                    return T26OuterData::from(['versions' => $versions]);
                }
            }
            PHP);

        $detailed = $this->audit()->classifyDetailed([
            ['routeName' => 't26.earlier.show', 'uri' => 'x', 'controllerFile' => $controllerFile, 'controllerClass' => null, 'actionMethod' => 'show'],
        ]);

        $this->assertSame('medium', $detailed[0]['tier']);
        $this->assertSame('T26OuterData', $detailed[0]['dtoClass']);
        $this->assertFalse($detailed[0]['ambiguous']);
    }

    /**
     * Regression test for a real false-positive caught live (ticket 25): a `*Data` construction
     * nested inside another `*Data` construction's OWN constructor arguments (a field value, e.g.
     * `WorkflowCatalogData::from(['guards' => GuardCatalogEntryData::collect($guards)])`) must NOT be
     * miscounted as a second, competing top-level DTO. `NodeFinder::findInstanceOf()` can't prune
     * traversal, so it previously walked into the outer match's own arguments and found the inner one
     * too; {@see SdkReturnsCoverageAudit::outermostDtoConstructions()} stops descending once a match
     * is found.
     */
    public function test_classify_ignores_a_dto_construction_nested_inside_another_dtos_constructor_arguments(): void
    {
        $controllerFile = $this->writeFixture(<<<'PHP'
            <?php
            class T26NestedFieldValueController {
                public function catalog() {
                    return T26OuterCatalogData::from([
                        'guards' => T26GuardEntryData::collect($this->guards()),
                        'types' => T26TypeOptionData::collect($this->types()),
                    ]);
                }
                private function guards(): array { return []; }
                private function types(): array { return []; }
            }
            PHP);

        $detailed = $this->audit()->classifyDetailed([
            ['routeName' => 't26.nested.catalog', 'uri' => 'catalog', 'controllerFile' => $controllerFile, 'controllerClass' => null, 'actionMethod' => 'catalog'],
        ]);

        $this->assertSame('medium', $detailed[0]['tier']);
        $this->assertSame('T26OuterCatalogData', $detailed[0]['dtoClass']);
        $this->assertFalse($detailed[0]['ambiguous']);
        $this->assertFalse($detailed[0]['many'], 'a single-object response with list-shaped FIELDS is not itself a list response');
    }

    /**
     * Sibling regression test for the same nested-construction bug, in {@see
     * SdkReturnsCoverageAudit::outermostConstructionIsCollectCall()} rather than
     * {@see SdkReturnsCoverageAudit::outermostDtoConstructions()}: a bare `::collect()`/
     * `::collection()` call nested inside another `*Data`'s constructor arguments must not signal
     * `many: true` for the outer (single-object) response. Caught live (ticket 25) via
     * `WorkflowController::catalog()`, whose real response is one `WorkflowCatalogData` object with
     * `::collect()`-built list fields (`guards`, `types`) — the unbounded check previously flagged
     * `many: true` incorrectly, which would have shipped a wrong `->returns(..., many: true)`.
     */
    public function test_classify_does_not_signal_many_for_a_collect_call_nested_inside_another_dtos_constructor_arguments(): void
    {
        $controllerFile = $this->writeFixture(<<<'PHP'
            <?php
            class T26NestedCollectController {
                public function catalog() {
                    return T26SingleCatalogData::from([
                        'guards' => T26GuardEntryData::collect($this->guards()),
                    ]);
                }
                private function guards(): array { return []; }
            }
            PHP);

        $detailed = $this->audit()->classifyDetailed([
            ['routeName' => 't26.nested-collect.catalog', 'uri' => 'catalog', 'controllerFile' => $controllerFile, 'controllerClass' => null, 'actionMethod' => 'catalog'],
        ]);

        $this->assertSame('medium', $detailed[0]['tier']);
        $this->assertSame('T26SingleCatalogData', $detailed[0]['dtoClass']);
        $this->assertFalse($detailed[0]['many']);
    }

    private function tierOf(SdkReturnsCoverageAudit $audit, string $controllerFile, string $actionMethod, string $routeName): ?string
    {
        $rows = $audit->classify([
            ['routeName' => $routeName, 'uri' => 'x', 'controllerFile' => $controllerFile, 'controllerClass' => null, 'actionMethod' => $actionMethod],
        ]);

        return $rows[0]['tier'];
    }

    private function writeFixture(string $source): string
    {
        $file = tempnam(sys_get_temp_dir(), 'sdk-returns-coverage-').'.php';
        file_put_contents($file, $source);
        $this->addTempFile($file);

        return $file;
    }

    /**
     * Like {@see writeFixture()}, but also `require`s the file — the call-chain-following tests
     * need the fixture class/trait genuinely declared (not just parseable) since
     * `classifyViaCallChain()` uses real reflection to resolve constructor property types and
     * trait/service method file locations, not just AST. One class/trait per file (nikic's
     * `methodsFor()` finds only the FIRST `ClassLike` node), so a trait and the class using it need
     * two separate calls, trait first.
     */
    private function requireFixtureClass(string $slug, string $source): string
    {
        $file = tempnam(sys_get_temp_dir(), 'sdk-returns-coverage-'.$slug.'-').'.php';
        file_put_contents($file, $source);
        $this->addTempFile($file);
        require $file;

        return $file;
    }

    /** @var list<string> */
    private array $tempFiles = [];

    private function addTempFile(string $file): void
    {
        $this->tempFiles[] = $file;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];

        parent::tearDown();
    }
}
