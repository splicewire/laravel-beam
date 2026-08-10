<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Surgeon\Operation\FixableFinding;
use Splicewire\Beam\Surgeon\SdkEndpointDriftAudit;

/**
 * The dogfood case (ADR-0124): a Saloon SDK request's `resolveEndpoint()` literal drifted from the
 * host's real route — the class returned `/api/v1/compositions/{id}/render` after the route moved to
 * `/api/v1/splice/compositions/{id}/render`. This proves {@see SdkEndpointDriftAudit}'s unique-suffix
 * match locates the corrected path and emits an applyable `literal-rewrite` suggestion (the beam POLICY
 * that nominates surgeon's generic literal-rewrite mechanism).
 *
 * Pure unit test over an in-memory fixture — no splicewire/laravel-connector package, no route table, no DB.
 */
class SdkEndpointDriftAuditTest extends TestCase
{
    private function audit(): SdkEndpointDriftAudit
    {
        return new SdkEndpointDriftAudit('/nonexistent'); // requestsDir unused by suggestFor()
    }

    public function test_it_corrects_a_drifted_endpoint_by_unique_suffix_match(): void
    {
        $literals = [[
            'file' => '/pkg/src/Requests/Compositions/TriggerRender.php',
            'literal' => '/api/v1/compositions/{$this->id}/render',
        ]];
        $routes = [
            'api/v1/splice/compositions/{id}/render',
            'api/v1/silos/{silo}',
        ];

        $findings = $this->audit()->suggestFor($literals, $routes);

        $this->assertCount(1, $findings);
        /** @var FixableFinding $finding */
        $finding = $findings[0];

        $this->assertTrue($finding->isFixable());
        $this->assertSame('fail', $finding->finding->status->value);
        $this->assertSame('sdk.endpoint-drift', $finding->finding->check);

        $this->assertNotNull($finding->suggestion);
        $this->assertSame('literal-rewrite', $finding->suggestion->kind);
        // The interpolation token is preserved in the corrected literal, not route-style {id}.
        $this->assertSame('/api/v1/compositions/{$this->id}/render', $finding->suggestion->payload['old']);
        $this->assertSame('/api/v1/splice/compositions/{$this->id}/render', $finding->suggestion->payload['new']);
        $this->assertSame($literals[0]['file'], $finding->suggestion->payload['file']);
    }

    public function test_an_exact_route_match_is_not_flagged(): void
    {
        $literals = [[
            'file' => '/pkg/CreateComposition.php',
            'literal' => '/api/v1/splice/compositions',
        ]];
        $routes = ['api/v1/splice/compositions'];

        $this->assertSame([], $this->audit()->suggestFor($literals, $routes));
    }

    public function test_an_ambiguous_suffix_is_advisory_not_auto_fixed(): void
    {
        $literals = [[
            'file' => '/pkg/GetThing.php',
            'literal' => '/api/v1/things/{$this->id}',
        ]];
        // Two real routes share the trailing `things/{id}` segment-run — the audit refuses to guess.
        $routes = [
            'api/v1/splice/things/{id}',
            'api/v1/legacy/things/{id}',
        ];

        $findings = $this->audit()->suggestFor($literals, $routes);

        $this->assertCount(1, $findings);
        $this->assertNull($findings[0]->suggestion);       // no fix, not advisory-kind — a plain Finding
        $this->assertFalse($findings[0]->isFixable());
        $this->assertFalse($findings[0]->isAdvisory());
        $this->assertSame('fail', $findings[0]->finding->status->value);
    }

    public function test_a_concatenation_style_literal_normalizes_and_matches(): void
    {
        // SDK expresses the param via PHP string concatenation, not interpolation. It must normalize to
        // `/api/x/{param}/y` and match the real route `api/x/{id}/y` — no false-positive drift.
        $literals = [[
            'file' => '/pkg/GetThing.php',
            'literal' => "/api/x/'.\$this->id.'/y",
        ]];
        $routes = ['api/x/{id}/y'];

        $this->assertSame([], $this->audit()->suggestFor($literals, $routes));
    }

    public function test_an_admin_prefix_literal_matches_an_admin_route(): void
    {
        // Route-set coverage: admin (`/api/admin/*`) surfaces are collected too, so an admin SDK
        // endpoint (here a trailing-concat param) matches rather than mismatching by absence.
        $literals = [[
            'file' => '/pkg/Tenants/ShowTenant.php',
            'literal' => "/api/admin/tenants/'.\$this->id",
        ]];
        $routes = ['api/admin/tenants/{tenant}'];

        $this->assertSame([], $this->audit()->suggestFor($literals, $routes));
    }

    public function test_it_extracts_concatenation_style_endpoint_literals_from_source(): void
    {
        $mid = <<<'PHP'
        <?php
        class ListChildTenants extends Request
        {
            public function resolveEndpoint(): string
            {
                return '/api/admin/tenants/'.$this->brokerId.'/children';
            }
        }
        PHP;
        $trailing = <<<'PHP'
        <?php
        class ShowTenant extends Request
        {
            public function resolveEndpoint(): string
            {
                return '/api/admin/tenants/'.$this->id;
            }
        }
        PHP;

        $this->assertSame(
            "/api/admin/tenants/'.\$this->brokerId.'/children",
            $this->audit()->extractEndpointLiteral($mid),
        );
        $this->assertSame(
            "/api/admin/tenants/'.\$this->id",
            $this->audit()->extractEndpointLiteral($trailing),
        );
    }

    public function test_it_extracts_interpolated_endpoint_literals_from_source(): void
    {
        $source = <<<'PHP'
        <?php
        class TriggerRender extends Request
        {
            public function resolveEndpoint(): string
            {
                return "/api/v1/compositions/{$this->id}/render";
            }
        }
        PHP;

        $this->assertSame(
            '/api/v1/compositions/{$this->id}/render',
            $this->audit()->extractEndpointLiteral($source),
        );
    }
}
