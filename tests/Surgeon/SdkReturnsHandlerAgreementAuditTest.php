<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Surgeon\SdkReturnsHandlerAgreementAudit;

/**
 * `client-sdk-codegen` ticket 06 — does an annotated route's handler send the shape it declares?
 *
 * Hermetic: no app boot, no router, no live routes. The fixture controllers below are real classes in
 * THIS file, so the audit's reflection-then-parse path runs for real against source it can actually
 * read — which is the path that matters, since the whole class of defect lives in what the parser can
 * and cannot see.
 *
 * The negative controls are the point. {@see test_a_dto_built_outside_the_return_does_not_count} is the
 * estate's founding instance (`IntegrationsController::rotateCredential()` writes a `LlmKeySecretData`
 * to the vault and returns a literal), and a whole-body DTO search reads it as AGREEING — so an audit
 * that passed only the positive tests would have been blind to the route it was built for.
 */
class SdkReturnsHandlerAgreementAuditTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function row(string $method, string $declared, array $overrides = []): array
    {
        return $overrides + [
            'routeName' => 'fixture.route',
            'uri' => 'fixture',
            'declared' => $declared,
            'controllerClass' => AgreementFixtureController::class,
            'actionMethod' => $method,
        ];
    }

    private function verdictFor(string $method, string $declared): array
    {
        $rows = (new SdkReturnsHandlerAgreementAudit([$this->row($method, $declared)]))->classify();

        return $rows[0];
    }

    public function test_a_handler_returning_the_declared_dto_agrees(): void
    {
        $this->assertSame(
            SdkReturnsHandlerAgreementAudit::VERDICT_AGREES,
            $this->verdictFor('returnsDeclared', 'App\\Data\\WidgetData')['verdict'],
        );
    }

    public function test_the_declared_class_is_matched_on_short_name_across_namespaces(): void
    {
        // The controller body writes the SHORT name its own `use` statements resolve; the route writes an
        // FQN. Comparing FQNs would report every correct route as a disagreement.
        $this->assertSame(
            SdkReturnsHandlerAgreementAudit::VERDICT_AGREES,
            $this->verdictFor('returnsDeclared', 'Some\\Other\\Namespace\\WidgetData')['verdict'],
        );
    }

    public function test_a_literal_json_envelope_contradicts_its_declaration(): void
    {
        $row = $this->verdictFor('returnsLiteralEnvelope', 'App\\Data\\WidgetData');

        $this->assertSame(SdkReturnsHandlerAgreementAudit::VERDICT_CONTRADICTS, $row['verdict']);
    }

    public function test_a_dto_built_outside_the_return_does_not_count(): void
    {
        // The founding instance, reduced. `rotateCredential()` constructs the declared DTO — into a vault —
        // and returns `['data' => ['rotated' => true]]`. Return-statement scoping is the only thing that
        // makes this visible; a whole-body search calls it AGREES.
        $row = $this->verdictFor('buildsDtoThenReturnsLiteral', 'App\\Data\\WidgetData');

        $this->assertSame(SdkReturnsHandlerAgreementAudit::VERDICT_CONTRADICTS, $row['verdict']);
    }

    public function test_a_closures_own_return_statement_is_not_the_actions_return(): void
    {
        // A `return` inside a closure the action merely *builds* belongs to that closure's body. Counting
        // it would make any action that passes a DTO-returning callback anywhere read as agreeing.
        $row = $this->verdictFor('returnsLiteralButBuildsADtoInAClosure', 'App\\Data\\WidgetData');

        $this->assertSame(SdkReturnsHandlerAgreementAudit::VERDICT_CONTRADICTS, $row['verdict']);
    }

    public function test_a_dto_built_by_a_callback_inside_the_return_expression_does_count(): void
    {
        // The deliberate other side of the line above, and the reason it is drawn at the STATEMENT rather
        // than at every closure: `return $items->map(fn ($i) => WidgetData::from($i))` genuinely is a
        // WidgetData response. Erring toward AGREES here is the safe direction — it can only cost a
        // missed finding, never a fabricated one.
        $this->assertSame(
            SdkReturnsHandlerAgreementAudit::VERDICT_AGREES,
            $this->verdictFor('returnsAMappedCollection', 'App\\Data\\WidgetData')['verdict'],
        );
    }

    public function test_a_different_dto_is_reported_as_a_fact_not_as_a_contradiction(): void
    {
        $row = $this->verdictFor('returnsDeclared', 'App\\Data\\GadgetData');

        $this->assertSame(SdkReturnsHandlerAgreementAudit::VERDICT_CONSTRUCTS_OTHER, $row['verdict']);
        $this->assertSame(['WidgetData'], $row['constructed']);
    }

    public function test_a_response_from_data_attribute_satisfies_the_declaration(): void
    {
        $this->assertSame(
            SdkReturnsHandlerAgreementAudit::VERDICT_AGREES,
            $this->verdictFor('declaresViaAttribute', 'App\\Data\\WidgetData')['verdict'],
        );
    }

    public function test_an_opaque_return_is_undetermined_never_a_contradiction(): void
    {
        // A variable return is not provably literal. The rule is a wrong label on a real finding, never a
        // fabricated finding — so this must not become a Warn.
        $row = $this->verdictFor('returnsAVariable', 'App\\Data\\WidgetData');

        $this->assertSame(SdkReturnsHandlerAgreementAudit::VERDICT_UNDETERMINED, $row['verdict']);
        $this->assertSame(SdkReturnsHandlerAgreementAudit::REASON_OPAQUE_RETURN, $row['reason']);
    }

    public function test_an_unresolvable_delegation_is_undetermined_with_the_delegates_reason(): void
    {
        $row = $this->verdictFor('delegatesToAnUnknownHop', 'App\\Data\\WidgetData');

        $this->assertSame(SdkReturnsHandlerAgreementAudit::VERDICT_UNDETERMINED, $row['verdict']);
        $this->assertSame(SdkReturnsHandlerAgreementAudit::REASON_DELEGATES, $row['reason']);
    }

    public function test_one_level_of_delegation_within_the_controller_is_followed(): void
    {
        $this->assertSame(
            SdkReturnsHandlerAgreementAudit::VERDICT_AGREES,
            $this->verdictFor('delegatesToOwnMethod', 'App\\Data\\WidgetData')['verdict'],
        );
    }

    public function test_a_closure_route_is_undetermined_rather_than_skipped(): void
    {
        $rows = (new SdkReturnsHandlerAgreementAudit([
            $this->row('x', 'App\\Data\\WidgetData', ['controllerClass' => null, 'actionMethod' => null]),
        ]))->classify();

        $this->assertSame(SdkReturnsHandlerAgreementAudit::VERDICT_UNDETERMINED, $rows[0]['verdict']);
        $this->assertSame(SdkReturnsHandlerAgreementAudit::REASON_NOT_A_CONTROLLER_ACTION, $rows[0]['reason']);
    }

    public function test_a_controller_this_host_did_not_vendor_is_undetermined(): void
    {
        $rows = (new SdkReturnsHandlerAgreementAudit([
            $this->row('index', 'App\\Data\\WidgetData', ['controllerClass' => 'Gone\\Package\\Controller']),
        ]))->classify();

        $this->assertSame(SdkReturnsHandlerAgreementAudit::REASON_CONTROLLER_UNRESOLVABLE, $rows[0]['reason']);
    }

    public function test_an_empty_population_is_inconclusive_not_a_pass(): void
    {
        // The whole point of the check: a zero must not read the same for "nothing disagrees" and
        // "nothing was looked at".
        $findings = (new SdkReturnsHandlerAgreementAudit([]))->run();

        $this->assertCount(1, $findings);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('reach reading, not a clean one', $findings[0]->detail);
    }

    public function test_a_run_with_undetermined_rows_reports_them_and_is_inconclusive(): void
    {
        $findings = (new SdkReturnsHandlerAgreementAudit([
            $this->row('returnsDeclared', 'App\\Data\\WidgetData'),
            $this->row('returnsLiteralEnvelope', 'App\\Data\\WidgetData'),
            $this->row('returnsAVariable', 'App\\Data\\WidgetData'),
        ]))->run();

        $warns = array_values(array_filter($findings, fn ($f) => $f->status === DoctorStatus::Warn));
        $this->assertCount(1, $warns);
        $this->assertStringContainsString('literal envelope', $warns[0]->detail);
        $this->assertSame('sdk.returns-handler-agreement', $warns[0]->check);

        // The summary is a real measurement and says so; the unmeasured tail is a SEPARATE finding
        // carrying the inconclusive flag. Folded together, a run over 209 routes renders as
        // "(measured nothing)" in the doctor — false in the direction that teaches readers to ignore
        // the flag. Measured 2026-09-03 at the flagship, which is why they are split.
        $summary = $findings[1];
        $this->assertTrue($summary->conclusive);
        $this->assertStringContainsString('1 agree, 1 contradict', $summary->detail);

        $tail = $findings[2];
        $this->assertFalse($tail->conclusive);
        $this->assertStringContainsString('No verdict could be reached for 1 of 3', $tail->detail);
        $this->assertStringContainsString('opaque-return: 1', $tail->detail);
        $this->assertStringContainsString('unmeasured, not clean', $tail->detail);
    }

    public function test_a_fully_agreeing_run_is_a_conclusive_pass(): void
    {
        $findings = (new SdkReturnsHandlerAgreementAudit([
            $this->row('returnsDeclared', 'App\\Data\\WidgetData'),
        ]))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertTrue($findings[0]->conclusive);
    }

    public function test_nothing_in_the_audit_ever_fails_the_build(): void
    {
        // Advisory by construction: which routes are mounted and what a handler resolves to are host
        // facts, so per AGENTS.md this axis may warn and may never throw or fail.
        $findings = (new SdkReturnsHandlerAgreementAudit([
            $this->row('returnsLiteralEnvelope', 'App\\Data\\WidgetData'),
            $this->row('returnsAVariable', 'App\\Data\\WidgetData'),
            $this->row('x', 'App\\Data\\WidgetData', ['controllerClass' => 'Gone\\Package\\Controller']),
        ]))->run();

        foreach ($findings as $finding) {
            $this->assertNotSame(DoctorStatus::Fail, $finding->status);
        }
    }
}

/**
 * Fixture controller — real source the audit parses off disk by reflection, exactly as it would a
 * vendored one. `WidgetData`/`GadgetData` need not exist as classes: the audit matches the `*Data`
 * naming convention in the AST, which is the same signal its sibling coverage audit uses.
 */
class AgreementFixtureController
{
    public function __construct(protected ?object $service = null) {}

    public function returnsDeclared()
    {
        return WidgetData::from(['id' => 1]);
    }

    public function returnsLiteralEnvelope()
    {
        return response()->json(['data' => ['rotated' => true]]);
    }

    public function buildsDtoThenReturnsLiteral()
    {
        $this->store(new WidgetData('a', 'b'));

        return response()->json(['data' => ['rotated' => true]]);
    }

    public function returnsLiteralButBuildsADtoInAClosure()
    {
        $this->wrap(function () {
            return WidgetData::from([]);
        });

        return response()->json(['ok' => true]);
    }

    public function returnsAMappedCollection()
    {
        return $this->items()->map(fn ($item) => WidgetData::from($item));
    }

    protected function items() {}

    #[ResponseFromData(WidgetData::class)]
    public function declaresViaAttribute()
    {
        return response()->json(['data' => []]);
    }

    public function returnsAVariable()
    {
        $payload = $this->compute();

        return $payload;
    }

    public function delegatesToAnUnknownHop()
    {
        return $this->service->build();
    }

    public function delegatesToOwnMethod()
    {
        return $this->buildWidget();
    }

    public function buildWidget()
    {
        return WidgetData::from([]);
    }

    protected function store($data) {}

    protected function wrap($callback) {}

    protected function compute() {}
}
