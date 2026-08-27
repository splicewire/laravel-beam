<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Schemastud\DataSchemas\Generators\JsonSchemaGenerator;
use Splicewire\Beam\Doctor\SchemaRoundTripAudit;
use Splicewire\Beam\Tests\Fixtures\RefusingFixtureGenerator;
use Splicewire\Beam\Tests\TestCase;

/**
 * The round-trip audit's job is "prove the generator this host CONFIGURED resolves and produces an
 * object schema". It used to build a bare `new JsonSchemaGenerator` with no config at all — the last
 * such site in beam's `src/` after beam-facade 105 — so it proved a generator no host generates
 * with, and could not see a `generators` list at all.
 */
class SchemaRoundTripAuditTest extends TestCase
{
    public function test_the_round_trip_uses_the_host_configured_generator(): void
    {
        config()->set('data-schemas.generators', [JsonSchemaGenerator::class]);

        $finding = (new SchemaRoundTripAudit)->run();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('name', $finding->detail);
        $this->assertStringContainsString('count', $finding->detail);
    }

    public function test_a_generator_list_that_accepts_nothing_warns_rather_than_throwing(): void
    {
        // ChainedGenerator::generate() throws when nothing accepts the class. An advisory doctor
        // audit must degrade to a WARN, never take the sweep down with it.
        config()->set('data-schemas.generators', [RefusingFixtureGenerator::class]);

        $finding = (new SchemaRoundTripAudit)->run();

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('no configured generator accepts', $finding->detail);
    }
}
