<?php

namespace Splicewire\Beam\Tests\Doctor;

use Illuminate\Support\Facades\File;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\ScribeOutputContractAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * ADR-0211 §8: the no-HTML guarantee reports, it does not gate — so every assertion here is about a
 * Warn/Pass, and none of them about a Fail.
 */
class ScribeOutputContractAuditTest extends TestCase
{
    private const EMITTER = 'Scribe emits the spec, beam renders it (no second docs UI)';

    private const ARTIFACT = 'an OpenAPI artifact exists to serve';

    private const REGENERATION = 'the spec regenerates on deploy';

    private const DESCRIBES = 'the spec describes at least one route';

    private string $artifact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artifact = storage_path('app/scribe/openapi.yaml');
        config([
            'beam.core.openapi.artifact' => $this->artifact,
            'scribe.type' => 'laravel',
            'scribe.laravel.add_routes' => false,
        ]);
    }

    protected function tearDown(): void
    {
        File::delete($this->artifact);

        parent::tearDown();
    }

    public function test_it_warns_when_scribe_would_serve_its_own_static_site(): void
    {
        config(['scribe.type' => 'static']);

        $finding = $this->finding(self::EMITTER);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('public/docs', $finding->detail);
    }

    public function test_it_warns_when_scribe_mounts_its_own_docs_route(): void
    {
        config(['scribe.laravel.add_routes' => true]);

        $finding = $this->finding(self::EMITTER);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('add_routes', $finding->detail);
    }

    public function test_it_warns_when_the_stub_was_never_published(): void
    {
        config(['scribe' => null]);

        $finding = $this->finding(self::EMITTER);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('beam-scribe', $finding->detail);
    }

    public function test_the_emitter_only_pair_passes(): void
    {
        $this->assertSame(DoctorStatus::Pass, $this->finding(self::EMITTER)->status);
    }

    public function test_it_warns_when_there_is_no_artifact(): void
    {
        $finding = $this->finding(self::ARTIFACT);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('404', $finding->detail);
    }

    public function test_a_present_artifact_passes(): void
    {
        $this->writeArtifact();

        $this->assertSame(DoctorStatus::Pass, $this->finding(self::ARTIFACT)->status);
    }

    public function test_it_warns_when_the_artifact_is_months_stale(): void
    {
        $this->writeArtifact();
        touch($this->artifact, time() - (60 * 86400));
        clearstatcache();

        $finding = $this->finding(self::ARTIFACT);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('60 days ago', $finding->detail);
    }

    public function test_it_warns_when_no_composer_script_regenerates_the_spec(): void
    {
        $base = $this->fixtureRepo(['scripts' => ['test' => 'vendor/bin/pest']]);

        $finding = $this->finding(self::REGENERATION, new ScribeOutputContractAudit($base));

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('scribe:generate', $finding->detail);
    }

    public function test_a_deploy_script_running_the_generator_passes(): void
    {
        $base = $this->fixtureRepo(['scripts' => ['docs' => '@php artisan scribe:generate']]);

        $this->assertSame(
            DoctorStatus::Pass,
            $this->finding(self::REGENERATION, new ScribeOutputContractAudit($base))->status,
        );
    }

    public function test_it_finds_the_generator_inside_a_multi_line_script(): void
    {
        $base = $this->fixtureRepo(['scripts' => [
            'deploy' => ['@php artisan migrate --force', '@php artisan scribe:generate'],
        ]]);

        $this->assertSame(
            DoctorStatus::Pass,
            $this->finding(self::REGENERATION, new ScribeOutputContractAudit($base))->status,
        );
    }

    /**
     * The regression this check exists for (ADR-0211 §7, amended): the artifact is present, generation
     * succeeded, and every other check passes — but the match rules named a route layout this host does
     * not have, so the document describes nothing and beam serves it anyway.
     */
    public function test_it_warns_when_the_generated_spec_describes_no_routes(): void
    {
        $this->writeArtifact("openapi: 3.1.0\ninfo:\n  title: Beam API\npaths: {}\n");

        $finding = $this->finding(self::DESCRIBES);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('ZERO routes', $finding->detail);
        $this->assertStringContainsString('route:list', $finding->detail);
    }

    public function test_a_paths_key_with_nothing_beneath_it_is_also_empty(): void
    {
        $this->writeArtifact("openapi: 3.1.0\npaths:\ncomponents:\n  schemas: {}\n");

        $this->assertSame(DoctorStatus::Warn, $this->finding(self::DESCRIBES)->status);
    }

    public function test_a_spec_with_paths_passes(): void
    {
        $this->writeArtifact(
            "openapi: 3.1.0\npaths:\n  /api/users:\n    get:\n      summary: List users\ncomponents: {}\n",
        );

        $this->assertSame(DoctorStatus::Pass, $this->finding(self::DESCRIBES)->status);
    }

    public function test_it_defers_to_the_artifact_check_when_there_is_no_artifact(): void
    {
        $finding = $this->finding(self::DESCRIBES);

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('No artifact to inspect', $finding->detail);
    }

    /** @param array<string, mixed> $manifest */
    private function fixtureRepo(array $manifest): string
    {
        $base = storage_path('framework/testing/scribe-audit');
        File::ensureDirectoryExists($base);
        File::put($base.'/composer.json', (string) json_encode($manifest));

        return $base;
    }

    private function finding(string $check, ?ScribeOutputContractAudit $audit = null): Finding
    {
        foreach (($audit ?? new ScribeOutputContractAudit)->run() as $finding) {
            if ($finding->check === $check) {
                return $finding;
            }
        }

        $this->fail("No finding for check '{$check}'.");
    }

    private function writeArtifact(?string $contents = null): void
    {
        File::ensureDirectoryExists(dirname($this->artifact));
        File::put($this->artifact, $contents ?? "openapi: 3.1.0\npaths:\n  /api/ping:\n    get: {}\n");
    }
}
