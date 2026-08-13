<?php

namespace Splicewire\Beam\Tests\Surgeon;

use PHPUnit\Framework\TestCase;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Surgeon\ClientRuntimeContractAudit;

/**
 * particle-doctrine-followups #12 — the client-runtime contract audit. The generated client imports
 * two host-owned modules (`beam.client.{client_import,routes_import}`); this audit reports a host
 * missing either module or exporting a shape that lacks a required symbol. Constructed directly with
 * module contents — no filesystem, no container — mirroring the injected-content discipline of the
 * sibling audits.
 */
class ClientRuntimeContractAuditTest extends TestCase
{
    private const CONFORMING_API = <<<'TS'
    export const api = {
        get: <T>(url: string) => request<T>('GET', url),
        post: <T>(url: string, body?: unknown) => request<T>('POST', url, body),
    };
    TS;

    private const CONFORMING_ROUTES = <<<'TS'
    import { defaults } from '@/generated/routes';

    export function route(name: string, params: Record<string, string | number> = {}): string {
        return defaults[name];
    }
    TS;

    private function audit(
        ?string $clientContent,
        ?string $routesContent,
        bool $operatorTier = false,
        ?string $clientImport = '@/lib/api',
        ?string $routesImport = '@/lib/routes',
    ): ClientRuntimeContractAudit {
        return new ClientRuntimeContractAudit(
            clientImport: $clientImport,
            routesImport: $routesImport,
            clientModulePath: str_starts_with($clientImport, '@/') ? '/host/resources/js/lib/api.ts' : null,
            routesModulePath: str_starts_with($routesImport, '@/') ? '/host/resources/js/lib/routes.ts' : null,
            clientModuleContent: $clientContent,
            routesModuleContent: $routesContent,
            operatorTier: $operatorTier,
        );
    }

    public function test_conforming_one_tier_modules_pass(): void
    {
        $findings = $this->audit(self::CONFORMING_API, self::CONFORMING_ROUTES)->run();

        $this->assertCount(2, $findings);
        foreach ($findings as $finding) {
            $this->assertSame(DoctorStatus::Pass, $finding->status);
        }
        $this->assertStringContainsString('`api`', $findings[0]->detail);
        $this->assertStringContainsString('`route`', $findings[1]->detail);
    }

    public function test_a_missing_module_is_reported_with_the_publish_tag_fix(): void
    {
        $findings = $this->audit(null, self::CONFORMING_ROUTES)->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString("'@/lib/api' resolves to /host/resources/js/lib/api.ts", $findings[0]->detail);
        $this->assertStringContainsString('beam-client-runtime', $findings[0]->detail);
        $this->assertSame(DoctorStatus::Pass, $findings[1]->status);
    }

    public function test_a_module_missing_a_required_export_is_reported(): void
    {
        // A routes module exporting only a helper — no `route` resolver.
        $findings = $this->audit(self::CONFORMING_API, 'export function urlFor(name: string) {}')->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertSame(DoctorStatus::Warn, $findings[1]->status);
        $this->assertStringContainsString('does not export `route`', $findings[1]->detail);
    }

    public function test_the_operator_tier_requires_the_operator_symbols(): void
    {
        // Both modules are one-tier conforming, but the host binds an operator source.
        $findings = $this->audit(self::CONFORMING_API, self::CONFORMING_ROUTES, operatorTier: true)->run();

        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('does not export `operatorApi`', $findings[0]->detail);
        $this->assertSame(DoctorStatus::Warn, $findings[1]->status);
        $this->assertStringContainsString('does not export `operatorRoute`', $findings[1]->detail);
    }

    public function test_brace_reexports_and_as_renames_resolve_to_the_exported_name(): void
    {
        // `export { client as api }` exports `api`; `export { api as apiClient }` does NOT export `api`.
        $renamedTo = 'const client = {};'."\n".'export { client as api };';
        $renamedAway = 'const api = {};'."\n".'export { api as apiClient };';

        $this->assertSame(DoctorStatus::Pass, $this->audit($renamedTo, self::CONFORMING_ROUTES)->run()[0]->status);
        $this->assertSame(DoctorStatus::Warn, $this->audit($renamedAway, self::CONFORMING_ROUTES)->run()[0]->status);
    }

    public function test_a_specifier_outside_the_alias_is_skipped_with_a_stated_pass(): void
    {
        // An npm-package runtime is legitimate; the static audit skips it AUDIBLY, never silently.
        $audit = new ClientRuntimeContractAudit(
            clientImport: '@acme/beam-runtime/api',
            routesImport: '@/lib/routes',
            clientModulePath: null,
            routesModulePath: '/host/resources/js/lib/routes.ts',
            clientModuleContent: null,
            routesModuleContent: self::CONFORMING_ROUTES,
            operatorTier: false,
        );

        $findings = $audit->run();

        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('not statically resolvable', $findings[0]->detail);
        $this->assertStringContainsString('skipped', $findings[0]->detail);
    }
}
