<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Http\ArrayResponseEnvelope;
use Splicewire\Beam\Http\Contracts\ResponseEnvelope;
use Splicewire\Beam\Http\ResponseBodyEnvelope;
use Splicewire\Beam\Surgeon\ResponseEnvelopeAudit;
use Splicewire\Beam\Tests\TestCase;

class ResponseEnvelopeAuditTest extends TestCase
{
    public function test_it_names_the_shipped_default_as_a_pass(): void
    {
        $finding = $this->only();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('ArrayResponseEnvelope', $finding->detail);
        $this->assertStringContainsString('shipped default', $finding->detail);
    }

    public function test_it_names_a_configured_richer_envelope_as_a_pass(): void
    {
        config(['beam.core.http.envelope' => ResponseBodyEnvelope::class]);

        $finding = $this->only();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('ResponseBodyEnvelope', $finding->detail);
    }

    /**
     * The fallback is the dangerous direction — a mistyped key silently DOWNGRADES the wire shape on a
     * host whose clients expect the rich one, behind working responses.
     */
    public function test_an_unusable_class_string_fails_and_says_which_shape_is_actually_served(): void
    {
        config(['beam.core.http.envelope' => 'Splicewire\\Beam\\Http\\NoSuchEnvelope']);

        $finding = $this->only();

        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('NoSuchEnvelope', $finding->detail);
        $this->assertStringContainsString('ArrayResponseEnvelope', $finding->detail);
    }

    public function test_a_direct_bind_outranking_the_key_is_reported_rather_than_hidden(): void
    {
        $this->app->bind(ResponseEnvelope::class, ResponseBodyEnvelope::class);

        $finding = $this->only();

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('ResponseBodyEnvelope', $finding->detail);
        $this->assertStringContainsString('ArrayResponseEnvelope', $finding->detail);
    }

    protected function only(): Finding
    {
        $findings = (new ResponseEnvelopeAudit($this->app))->run();

        $this->assertCount(1, $findings);
        $this->assertSame(ResponseEnvelopeAudit::CHECK, $findings[0]->check);

        return $findings[0];
    }

    public function test_the_default_is_still_the_neutral_envelope(): void
    {
        $this->assertSame(ArrayResponseEnvelope::class, config('beam.core.http.envelope'));
    }
}
