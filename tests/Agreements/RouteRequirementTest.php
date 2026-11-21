<?php

namespace Splicewire\Beam\Tests\Agreements;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Splicewire\Beam\Agreements\Data\OutstandingRequirementData;
use Splicewire\Beam\Agreements\Http\EnforceRouteRequirements;
use Splicewire\Beam\Agreements\Http\RequireAgreement;
use Splicewire\Beam\Agreements\Http\RouteRequirementGate;
use Splicewire\Beam\Agreements\RequirementDeclarations;
use Splicewire\Beam\Agreements\RequirementResolver;
use Splicewire\Beam\Agreements\Requires;
use Splicewire\Beam\Agreements\SlotRequirementDeclarations;
use Splicewire\Beam\Doctor\UnnamedRequirementRouteAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * agreements 07: an ordinary route requires an Agreement (ADR-0247 amendment 07; lead rulings Q-07a..e).
 */
class RouteRequirementTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        // The `web` group encrypts cookies and starts a session: give it a key (test-only, random per run).
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('route_req_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });
        RouteReqReport::query()->create(['title' => 'Q3']);
    }

    // ---- helpers ------------------------------------------------------------------------------------------------

    /** A fake agreements seam: outstanding `terms` for every behavior in $gated until $accepted flips. */
    private function bindSeam(array $gated = [], ?RouteReqSeamProbe $probe = null): RouteReqSeamProbe
    {
        $probe ??= new RouteReqSeamProbe;
        $probe->gated = $gated;
        $this->app->instance(RequirementResolver::class, $probe);

        return $probe;
    }

    private function routes(callable $define): void
    {
        Route::middleware('web')->group($define);
        Route::get('/login', fn () => 'login')->name('login');
        app('router')->getRoutes()->refreshNameLookups();
    }

    private function prompt(): void
    {
        Route::middleware('web')->get('/agreements/required', fn () => 'prompt')->name(RouteRequirementGate::PROMPT_ROUTE);
        app('router')->getRoutes()->refreshNameLookups();
    }

    // ---- no seam ---------------------------------------------------------------------------------------------------

    public function test_with_no_seam_a_gated_route_answers_exactly_as_without_the_middleware(): void
    {
        $this->routes(function (): void {
            Route::get('/plain', fn () => response()->json(['ok' => true]))->name('plain');
            Route::get('/gated', fn () => response()->json(['ok' => true]))->name('gated')->middleware('agreement:terms');
        });
        $this->actingAs(new RouteReqUser);

        $plain = $this->getJson('/plain');
        $gated = $this->getJson('/gated');
        $plain->assertOk();
        $this->assertSame($plain->getContent(), $gated->getContent());
        $this->assertSame($plain->status(), $gated->status());
        $this->assertSame($this->get('/plain')->getContent(), $this->get('/gated')->getContent());
    }

    // ---- refusal shapes ----------------------------------------------------------------------------------------

    public function test_a_data_request_is_refused_with_428_and_the_declared_body(): void
    {
        $seam = $this->bindSeam(['route:reports.export']);
        $this->prompt();
        $this->routes(fn () => Route::get('/reports/export', fn () => ['ok' => true])->name('reports.export')->middleware('agreement:terms'));
        $this->actingAs(new RouteReqUser);

        $this->getJson('/reports/export')
            ->assertStatus(428)
            ->assertJsonPath('requirements.0.key', 'terms')
            ->assertJsonPath('requirements.0.acceptOperation', 'agreements.accept');
        $this->assertSame([['behavior' => 'route:reports.export', 'subject' => null]], $seam->asked);
    }

    public function test_a_page_visit_is_sent_to_the_prompt_with_its_intended_destination(): void
    {
        $this->bindSeam(['route:reports.index']);
        $this->prompt();
        $this->routes(fn () => Route::get('/reports', fn () => 'reports')->name('reports.index')->middleware('agreement:terms'));
        $this->actingAs(new RouteReqUser);

        $this->get('/reports?tab=q3')
            ->assertStatus(303)
            ->assertRedirect(route(RouteRequirementGate::PROMPT_ROUTE))
            ->assertSessionHas(RouteRequirementGate::SESSION_KEY.'.intended', url('/reports?tab=q3'))
            ->assertSessionHas(RouteRequirementGate::SESSION_KEY.'.behavior', 'route:reports.index')
            ->assertSessionHas(RouteRequirementGate::SESSION_KEY.'.refusal.requirements.0.key', 'terms');

        // An Inertia visit, full or partial, is a page visit too (Q-07d), though it asks for JSON.
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Data' => 'rows', 'Accept' => 'application/json'])
            ->get('/reports')
            ->assertStatus(303);
    }

    public function test_a_refused_non_get_page_request_returns_to_the_previous_page_and_is_not_replayed(): void
    {
        $this->bindSeam(['route:reports.store']);
        $this->prompt();
        $this->routes(fn () => Route::post('/reports', fn () => 'stored')->name('reports.store')->middleware('agreement:terms'));
        $this->actingAs(new RouteReqUser);

        $this->from('/reports/new')->post('/reports')
            ->assertStatus(303)
            ->assertSessionHas(RouteRequirementGate::SESSION_KEY.'.intended', url('/reports/new'));
    }

    public function test_with_a_seam_but_no_prompt_route_a_page_visit_gets_the_428(): void
    {
        $this->bindSeam(['route:reports.index']);
        $this->routes(fn () => Route::get('/reports', fn () => 'reports')->name('reports.index')->middleware('agreement:terms'));
        $this->actingAs(new RouteReqUser);

        $this->get('/reports')->assertStatus(428);
    }

    public function test_accepted_lets_the_request_through(): void
    {
        $seam = $this->bindSeam(['route:reports.index']);
        $seam->accepted = true;
        $this->prompt();
        $this->routes(fn () => Route::get('/reports', fn () => 'reports')->name('reports.index')->middleware('agreement:terms'));
        $this->actingAs(new RouteReqUser);

        $this->get('/reports')->assertOk()->assertSee('reports');
    }

    // ---- subject, provider attachment, auth order -------------------------------------------------------------

    public function test_the_named_parameter_s_bound_record_is_the_subject(): void
    {
        $seam = $this->bindSeam(['route:reports.show']);
        $this->routes(fn () => Route::get('/reports/{report}', fn (RouteReqReport $report) => $report->title)
            ->name('reports.show')->middleware('agreement:data-use,report'));
        $this->actingAs(new RouteReqUser);

        $this->getJson('/reports/1')->assertStatus(428);
        $this->assertInstanceOf(RouteReqReport::class, $seam->subjects[0]);
        $this->assertSame(1, $seam->subjects[0]->getKey());
        $this->assertEquals(
            [new Requires('data-use', scope: 'subject')],
            app(SlotRequirementDeclarations::class)->requirementsFor('route:reports.show'),
        );
    }

    public function test_a_requirement_attached_to_a_route_name_gates_it_without_editing_the_route(): void
    {
        $seam = $this->bindSeam(['route:billing.export']);
        $this->app->instance(RequirementDeclarations::class, new RouteReqDeclarations(['route:billing.export']));
        $this->routes(fn () => Route::get('/billing/export', fn () => ['ok' => true])->name('billing.export'));
        $this->actingAs(new RouteReqUser);

        $this->getJson('/billing/export')->assertStatus(428);
        $this->assertSame('route:billing.export', $seam->asked[0]['behavior']);
    }

    public function test_a_guest_is_handled_by_the_route_s_own_authentication_and_never_reaches_the_gate(): void
    {
        $seam = $this->bindSeam(['route:reports.index']);
        $this->prompt();
        $this->routes(fn () => Route::get('/reports', fn () => 'reports')->name('reports.index')->middleware(['agreement:terms', 'auth']));

        $this->getJson('/reports')->assertUnauthorized();
        $this->get('/reports')->assertRedirect('/login');
        $this->assertSame([], $seam->asked);
    }

    public function test_both_route_gates_sort_after_authentication_and_binding(): void
    {
        $priority = app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewarePriority();
        $after = max(
            array_search(\Illuminate\Routing\Middleware\SubstituteBindings::class, $priority, true),
            array_search(\Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class, $priority, true),
        );
        $this->assertGreaterThan($after, array_search(EnforceRouteRequirements::class, $priority, true));
        $this->assertGreaterThan($after, array_search(RequireAgreement::class, $priority, true));
        $this->assertContains(EnforceRouteRequirements::class, app('router')->getMiddlewareGroups()['web']);
    }

    // ---- inertness (root's condition) ------------------------------------------------------------------------

    public function test_the_global_middleware_is_inert_for_an_undeclared_route_one_lookup_no_resolver_no_query(): void
    {
        $this->routes(fn () => Route::get('/rows', fn () => response()->json(RouteReqReport::query()->get()))->name('rows'));
        $this->actingAs(new RouteReqUser);

        DB::enableQueryLog();
        $before = $this->getJson('/rows');
        $baseQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        $seam = $this->bindSeam([]);
        $declarations = new RouteReqDeclarations([]);
        $this->app->instance(RequirementDeclarations::class, $declarations);
        $after = $this->getJson('/rows');

        $this->assertSame($before->getContent(), $after->getContent());
        $this->assertSame($before->status(), $after->status());
        $this->assertSame($baseQueries, count(DB::getQueryLog()), 'the gate adds no query');
        $this->assertSame([], $seam->asked, 'the resolver is never called');
        $this->assertSame(['route:rows'], $declarations->lookups, 'one port lookup for the route');
    }

    // ---- naming --------------------------------------------------------------------------------------------------

    public function test_an_unnamed_route_carrying_the_middleware_throws_and_fails_the_doctor_check(): void
    {
        $this->bindSeam(['route:x']);
        Route::middleware('web')->get('/unnamed', fn () => 'x')->middleware('agreement:terms');
        $this->actingAs(new RouteReqUser);
        $this->withoutExceptionHandling();

        $finding = collect(app(UnnamedRequirementRouteAudit::class)->run())->firstWhere('check', UnnamedRequirementRouteAudit::CHECK);
        $this->assertSame(\Rushing\Doctor\DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('GET|HEAD unnamed', $finding->detail);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no name');
        $this->get('/unnamed');
    }

    // ---- review P2 1: same-origin destinations only ----------------------------------------------------------

    public function test_a_foreign_referer_never_becomes_back_or_a_non_get_intended(): void
    {
        $this->bindSeam(['route:reports.index', 'route:reports.store']);
        $this->prompt();
        $this->routes(function (): void {
            Route::get('/reports', fn () => 'reports')->name('reports.index')->middleware('agreement:terms');
            Route::post('/reports', fn () => 'stored')->name('reports.store')->middleware('agreement:terms');
        });
        $this->actingAs(new RouteReqUser);

        // A foreign host (or a protocol-relative one) falls back to home.
        foreach (['https://evil.example/phish', '//evil.example/x', 'http://localhost.evil.example/'] as $referer) {
            $this->withHeader('referer', $referer)->get('/reports')
                ->assertStatus(303)
                ->assertSessionHas(RouteRequirementGate::SESSION_KEY.'.back', url('/'))
                ->assertSessionHas(RouteRequirementGate::SESSION_KEY.'.intended', url('/reports'));

            $this->withHeader('referer', $referer)->post('/reports')
                ->assertStatus(303)
                ->assertSessionHas(RouteRequirementGate::SESSION_KEY.'.intended', url('/'));
        }

        // A `javascript:` Referer never leaves the app: Laravel's url()->previous() already reads it as a path on this
        // origin, and whatever is stored is same-origin (the property the ruling asks for).
        $sameOrigin = fn (string $url): bool => RouteRequirementGate::sameOrigin($url, 'FOREIGN') === $url;
        $this->withHeader('referer', 'javascript:alert(1)')->get('/reports')->assertStatus(303);
        $this->assertTrue($sameOrigin(session(RouteRequirementGate::SESSION_KEY.'.back')));
        $this->withHeader('referer', 'javascript:alert(1)')->post('/reports')->assertStatus(303);
        $this->assertTrue($sameOrigin(session(RouteRequirementGate::SESSION_KEY.'.intended')));
        $this->assertStringStartsWith(url('/'), session(RouteRequirementGate::SESSION_KEY.'.intended'));

        // A same-origin Referer is kept.
        $this->withHeader('referer', url('/reports/new'))->post('/reports')
            ->assertSessionHas(RouteRequirementGate::SESSION_KEY.'.intended', url('/reports/new'))
            ->assertSessionHas(RouteRequirementGate::SESSION_KEY.'.back', url('/reports/new'));
    }

    public function test_same_origin_accepts_only_this_app_s_paths_and_urls(): void
    {
        $home = url('/');
        $this->assertSame('/reports?q=1', RouteRequirementGate::sameOrigin('/reports?q=1', $home));
        $this->assertSame(url('/x'), RouteRequirementGate::sameOrigin(url('/x'), $home));
        foreach (['//evil.example', '/\\evil.example', 'javascript:alert(1)', 'https://evil.example', 'ftp://localhost/', '', null] as $bad) {
            $this->assertSame($home, RouteRequirementGate::sameOrigin($bad, $home), (string) $bad);
        }
        $port = preg_replace('#^(https?://[^/:]+)#', '$1:8443', $home);
        $this->assertSame($home, RouteRequirementGate::sameOrigin($port, $home), 'another port is another origin');
    }

    // ---- review P2 2: a session-less group gets the data refusal ---------------------------------------------

    public function test_a_browser_request_on_a_stateless_api_route_gets_the_428_not_a_500(): void
    {
        $this->bindSeam(['route:api.reports']);
        $this->prompt();
        Route::middleware('api')->get('/api/reports', fn () => ['ok' => true])->name('api.reports')->middleware('agreement:terms');
        app('router')->getRoutes()->refreshNameLookups();
        $this->actingAs(new RouteReqUser);

        $this->withHeaders(['Accept' => 'text/html'])->get('/api/reports')
            ->assertStatus(428)
            ->assertJsonPath('requirements.0.key', 'terms');
    }

    // ---- review P2 3: subject scope needs a subject parameter -------------------------------------------------

    public function test_subject_scope_without_a_parameter_is_rejected_by_the_helper(): void
    {
        $this->expectException(LogicException::class);
        RequireAgreement::using('terms', scope: 'subject');
    }

    public function test_subject_scope_spelled_without_a_parameter_throws_on_the_route_and_fails_the_doctor_check(): void
    {
        $this->bindSeam(['route:reports.mine']);
        $this->routes(fn () => Route::get('/mine', fn () => 'mine')->name('reports.mine')->middleware('agreement:terms,,subject'));
        $this->actingAs(new RouteReqUser);

        $subject = collect(app(UnnamedRequirementRouteAudit::class)->run())->firstWhere('check', UnnamedRequirementRouteAudit::CHECK_SUBJECT);
        $this->assertSame(\Rushing\Doctor\DoctorStatus::Fail, $subject->status);
        $this->assertStringContainsString('route:reports.mine', $subject->detail);

        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('names no route parameter');
        $this->get('/mine');
    }

    public function test_a_provider_attached_subject_requirement_on_a_route_without_a_parameter_is_rejected(): void
    {
        $seam = $this->bindSeam(['route:billing.export']);
        $this->app->instance(RequirementDeclarations::class, new RouteReqDeclarations(['route:billing.export'], 'subject'));
        $this->routes(fn () => Route::get('/billing/export', fn () => 'x')->name('billing.export'));
        $this->actingAs(new RouteReqUser);

        $subject = collect(app(UnnamedRequirementRouteAudit::class)->run())->firstWhere('check', UnnamedRequirementRouteAudit::CHECK_SUBJECT);
        $this->assertSame(\Rushing\Doctor\DoctorStatus::Fail, $subject->status);

        $this->withoutExceptionHandling();
        try {
            $this->get('/billing/export');
            $this->fail('expected a LogicException');
        } catch (LogicException $e) {
            $this->assertStringContainsString('billing.export', $e->getMessage());
        }
        $this->assertSame([], $seam->asked, 'never asked: the configuration is rejected first');
    }

    // ---- review P2 3, completion: the subject parameter must exist and bind a model ----------------------------

    public function test_a_subject_parameter_the_route_does_not_have_fails_loud_and_is_flagged(): void
    {
        $seam = $this->bindSeam(['route:reports.missing']);
        $this->routes(fn () => Route::get('/reports/{report}/x', fn (RouteReqReport $report) => 'x')
            ->name('reports.missing')->middleware('agreement:terms,missing'));
        $this->actingAs(new RouteReqUser);

        $finding = collect(app(UnnamedRequirementRouteAudit::class)->run())->firstWhere('check', UnnamedRequirementRouteAudit::CHECK_SUBJECT);
        $this->assertSame(\Rushing\Doctor\DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('[missing] is not a parameter of the route', $finding->detail);

        $this->withoutExceptionHandling();
        try {
            $this->get('/reports/1/x');
            $this->fail('expected a LogicException');
        } catch (LogicException $e) {
            $this->assertStringContainsString('reports.missing', $e->getMessage());
            $this->assertStringContainsString('[missing]', $e->getMessage());
        }
        $this->assertSame([], $seam->asked, 'it never prompts');
    }

    public function test_a_scalar_subject_parameter_fails_loud_and_is_flagged(): void
    {
        $seam = $this->bindSeam(['route:reports.slug']);
        $this->routes(fn () => Route::get('/reports/by/{slug}', fn (string $slug) => $slug)
            ->name('reports.slug')->middleware('agreement:terms,slug'));
        $this->actingAs(new RouteReqUser);

        $finding = collect(app(UnnamedRequirementRouteAudit::class)->run())->firstWhere('check', UnnamedRequirementRouteAudit::CHECK_SUBJECT);
        $this->assertSame(\Rushing\Doctor\DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('[slug] has no model binding', $finding->detail);

        $this->withoutExceptionHandling();
        try {
            $this->get('/reports/by/q3');
            $this->fail('expected a LogicException');
        } catch (LogicException $e) {
            $this->assertStringContainsString("isn't bound to a model", $e->getMessage());
        }
        $this->assertSame([], $seam->asked, 'it never prompts');
    }

    public function test_a_bound_model_subject_is_unchanged_it_prompts_and_a_subject_assent_satisfies_it(): void
    {
        $seam = $this->bindSeam(['route:reports.show']);
        $this->prompt();
        $this->routes(fn () => Route::get('/reports/{report}', fn (RouteReqReport $report) => $report->title)
            ->name('reports.show')->middleware('agreement:data-use,report'));
        $this->actingAs(new RouteReqUser);

        $finding = collect(app(UnnamedRequirementRouteAudit::class)->run())->firstWhere('check', UnnamedRequirementRouteAudit::CHECK_SUBJECT);
        $this->assertSame(\Rushing\Doctor\DoctorStatus::Pass, $finding->status);

        $this->get('/reports/1')->assertStatus(303)->assertRedirect(route(RouteRequirementGate::PROMPT_ROUTE));
        $this->assertInstanceOf(RouteReqReport::class, $seam->subjects[0]);

        $seam->accepted = true; // the subject Assent is recorded
        $this->get('/reports/1')->assertOk()->assertSee('Q3');
    }

    public function test_the_helper_spells_the_middleware(): void
    {
        $this->assertSame('agreement:terms', RequireAgreement::using('terms'));
        $this->assertSame('agreement:data-use,report', RequireAgreement::using('data-use', subject: 'report'));
        $this->assertSame('agreement:terms,,organisation', RequireAgreement::using('terms', scope: 'organisation'));
    }
}

class RouteReqUser extends User
{
    protected $table = 'users';

    public $exists = true;

    protected $attributes = ['id' => 7];
}

class RouteReqReport extends Model
{
    protected $table = 'route_req_reports';

    public $timestamps = false;

    protected $guarded = [];
}

class RouteReqSeamProbe implements RequirementResolver
{
    /** @var list<string> */
    public array $gated = [];

    public bool $accepted = false;

    /** @var list<array{behavior: string, subject: mixed}> */
    public array $asked = [];

    /** @var list<mixed> */
    public array $subjects = [];

    public function outstanding(mixed $party, string $behavior, mixed $subject, array $submittedMembers): array
    {
        $this->asked[] = ['behavior' => $behavior, 'subject' => $subject instanceof Model ? null : $subject];
        $this->subjects[] = $subject;

        if ($this->accepted || ! in_array($behavior, $this->gated, true)) {
            return [];
        }

        return [new OutstandingRequirementData(
            key: 'terms', version: 1, digest: str_repeat('d', 64), kind: 'contract', title: 'Terms', scope: 'party',
            subject: null, readUrl: '/beam/agreements/agreements/terms', acceptOperation: 'agreements.accept', member: null,
        )];
    }
}

class RouteReqDeclarations implements RequirementDeclarations
{
    /** @var list<string> */
    public array $lookups = [];

    /** @param list<string> $declared */
    public function __construct(private array $declared, private string $scope = 'party') {}

    public function declares(string $behavior): bool
    {
        $this->lookups[] = $behavior;

        return in_array($behavior, $this->declared, true);
    }

    public function requirementsFor(string $behavior): array
    {
        return in_array($behavior, $this->declared, true) ? [new Requires('terms', scope: $this->scope)] : [];
    }

    public function all(): array
    {
        return array_fill_keys($this->declared, [new Requires('terms', scope: $this->scope)]);
    }
}
