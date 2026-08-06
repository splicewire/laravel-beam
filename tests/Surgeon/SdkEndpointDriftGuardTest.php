<?php

namespace Splicewire\Beam\Tests\Surgeon;

use ReflectionProperty;
use Splicewire\Beam\Surgeon\SdkEndpointDriftAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * The endpoint half of the client-sdk-regen #10 CI drift guard: the real splicewire/client SDK must
 * carry NO auto-fixable endpoint drift against the host's real route table. A fixable finding means a
 * request class's `resolveEndpoint()` literal drifted to a path that has a unique corrected route —
 * i.e. a route moved and the committed SDK wasn't repointed.
 *
 * Advisories (no-unique-match / ambiguous) are NOT asserted against — they're genuine SDK-vs-route
 * mismatches surfaced for manual review, not machine-fixable drift.
 *
 * This boots a host (so `Route::getRoutes()` resolves) but needs no tenant and no DB writes. If the SDK
 * checkout isn't present (a testbench boot / fresh clone without the co-dev packages), it skips rather
 * than fails — the meaningful assertion lands where a real route table + SDK both exist.
 */
class SdkEndpointDriftGuardTest extends TestCase
{
    public function test_it_reports_zero_auto_fixable_endpoint_drift(): void
    {
        $audit = SdkEndpointDriftAudit::forClientPackage();

        // The requests dir is the audit's sole constructor arg; skip if the co-dev SDK isn't checked out.
        $prop = new ReflectionProperty(SdkEndpointDriftAudit::class, 'requestsDir');
        $prop->setAccessible(true);
        $dir = $prop->getValue($audit);

        if (! is_dir($dir)) {
            $this->markTestSkipped("splicewire/client requests dir not present ({$dir}); co-dev SDK not checked out.");
        }

        $findings = $audit->suggestOperations();
        $fixable = array_values(array_filter($findings, fn ($f) => $f->isFixable()));
        $detail = implode("\n", array_map(fn ($f) => '  - '.$f->finding->detail, $fixable));

        $this->assertSame(
            [],
            $fixable,
            "Auto-fixable SDK endpoint drift detected — the SDK must be regenerated/repointed:\n{$detail}",
        );
    }
}
