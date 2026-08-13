<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Surgeon\SdkReturnsTypeScriptResolutionAudit;

/**
 * Ticket 29 item D — the backstop that checks the actual END RESULT (does the class resolve, does it
 * produce real emitted TS output) rather than re-deriving the emission rules. `check()` is the pure
 * core: plain arrays in, no router/filesystem beyond an already-read `$dtsContent` string.
 */
class SdkReturnsTypeScriptResolutionAuditTest extends TestCase
{
    private function audit(): SdkReturnsTypeScriptResolutionAudit
    {
        return new SdkReturnsTypeScriptResolutionAudit([], [], null);
    }

    public function test_an_unresolvable_config_binding_is_a_fail_finding_naming_key_and_class(): void
    {
        // Particle-doctrine-followups #04: forApp()'s class_exists guard used to silently continue —
        // the exact swallow that hid a stale config FQN until it cascaded into an outage.
        $audit = new SdkReturnsTypeScriptResolutionAudit([], [], null, [
            ['key' => 'beam.client.sources.admin', 'class' => 'App\\Gone\\AdminSource'],
        ]);

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertSame(SdkReturnsTypeScriptResolutionAudit::CHECK, $findings[0]->check);
        $this->assertStringContainsString('beam.client.sources.admin', $findings[0]->detail);
        $this->assertStringContainsString('App\\Gone\\AdminSource', $findings[0]->detail);
    }

    public function test_no_unresolvable_bindings_adds_no_findings(): void
    {
        $this->assertSame([], $this->audit()->run());
    }

    public function test_a_clean_returns_reference_produces_no_finding(): void
    {
        $findings = $this->audit()->check(
            rawReferences: [
                ['routeName' => 'a.index', 'uri' => 'a', 'source' => 'a.index', 'field' => 'returns', 'class' => self::class],
            ],
            resolvedEntries: [
                'a.index' => ['returns' => 'App.Data.SdkReturnsTypeScriptResolutionAuditTest'],
            ],
            dtsContent: 'declare namespace App { namespace Data { interface SdkReturnsTypeScriptResolutionAuditTest {} } }',
        );

        $this->assertSame([], $findings);
    }

    public function test_an_unresolvable_class_produces_a_fail_finding_and_skips_the_dts_check(): void
    {
        $findings = $this->audit()->check(
            rawReferences: [
                ['routeName' => 'b.index', 'uri' => 'b', 'source' => 'b.index', 'field' => 'returns', 'class' => 'SomeUnimportedClass'],
            ],
            resolvedEntries: [
                // Deliberately present, to prove check 1 short-circuits before check 2 even looks here.
                'b.index' => ['returns' => 'App.Data.SomeUnimportedClass'],
            ],
            dtsContent: 'declare namespace App { namespace Data {} }',
        );

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString('does not resolve to a real class', $findings[0]->detail);
        $this->assertStringContainsString('SomeUnimportedClass', $findings[0]->detail);
    }

    public function test_a_resolvable_class_with_no_matching_dts_declaration_produces_a_warn_finding(): void
    {
        $findings = $this->audit()->check(
            rawReferences: [
                ['routeName' => 'c.index', 'uri' => 'c', 'source' => 'c.index', 'field' => 'returns', 'class' => self::class],
            ],
            resolvedEntries: [
                'c.index' => ['returns' => 'App.Data.SomethingNeverEmitted'],
            ],
            dtsContent: 'declare namespace App { namespace Data { interface SomeOtherThing {} } }',
        );

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('no matching declaration was found', $findings[0]->detail);
        $this->assertStringContainsString('App.Data.SomethingNeverEmitted', $findings[0]->detail);
    }

    public function test_a_streams_reference_checks_the_right_event_names_list(): void
    {
        $findings = $this->audit()->check(
            rawReferences: [
                ['routeName' => 'circuits.run', 'uri' => 'circuits/run', 'source' => 'circuits.run', 'field' => 'streams:node_status', 'class' => self::class],
            ],
            resolvedEntries: [
                'circuits.run' => ['streams' => [
                    'node_status' => ['App.Data.NodeRunningEventData', 'App.Data.NodeCompleteEventData'],
                    'run_status' => ['App.Data.RunStatusEventData'],
                ]],
            ],
            dtsContent: 'declare namespace App { namespace Data { interface NodeRunningEventData {} interface NodeCompleteEventData {} } }',
        );

        $this->assertSame([], $findings);
    }

    public function test_missing_generated_dts_degrades_to_check_one_only(): void
    {
        $findings = $this->audit()->check(
            rawReferences: [
                ['routeName' => 'd.index', 'uri' => 'd', 'source' => 'd.index', 'field' => 'returns', 'class' => self::class],
            ],
            resolvedEntries: [
                'd.index' => ['returns' => 'App.Data.WhateverNotChecked'],
            ],
            dtsContent: null,
        );

        $this->assertSame([], $findings);
    }

    public function test_a_route_missing_from_resolved_entries_is_skipped_not_flagged(): void
    {
        // Simulates a realm whose manifest build threw (forApp()'s per-realm try/catch) — the route
        // still resolves (check 1 passes) but has no computed path to check, so check 2 is silently
        // skipped for it rather than producing a spurious finding.
        $findings = $this->audit()->check(
            rawReferences: [
                ['routeName' => 'e.index', 'uri' => 'e', 'source' => 'e.index', 'field' => 'returns', 'class' => self::class],
            ],
            resolvedEntries: [],
            dtsContent: 'declare namespace App { namespace Data {} }',
        );

        $this->assertSame([], $findings);
    }

    public function test_an_empty_input_set_produces_no_findings(): void
    {
        $this->assertSame([], $this->audit()->check([], [], null));
    }
}
