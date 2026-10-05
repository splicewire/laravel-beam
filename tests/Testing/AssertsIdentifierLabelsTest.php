<?php

namespace Splicewire\Beam\Tests\Testing;

use PHPUnit\Framework\AssertionFailedError;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Schema\IdentifierLabels;
use Splicewire\Beam\Testing\AssertsIdentifierLabels;
use Splicewire\Beam\Tests\Doctor\IdentifierLabelRawEnum;
use Splicewire\Beam\Tests\Doctor\IdentifierLabelResourceData;
use Splicewire\Beam\Tests\TestCase;

require_once __DIR__.'/../Doctor/IdentifierLabelAuditTest.php';

/** The `schema.identifier-label` ratchet's own rules (app-walkthrough APP-09): unlisted fails, stale fails, exact passes. */
class AssertsIdentifierLabelsTest extends TestCase
{
    use AssertsIdentifierLabels;

    /** @var array<string, string> */
    private array $ratchet = [];

    protected function identifierLabelRatchet(): array
    {
        return $this->ratchet;
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'fixture-ratchet',
            name: 'run',
            kind: OperationKind::Write,
            model: BeamParticle::class,
            handle: fn () => null,
            input: IdentifierLabelResourceData::class,
        ));
    }

    /** Whatever this package itself mounts, each with an owner. */
    private function everythingFound(): array
    {
        return array_map(fn () => 'APP-09', app(IdentifierLabels::class)->find());
    }

    public function test_an_unlisted_class_fails(): void
    {
        $this->ratchet = $this->everythingFound();
        unset($this->ratchet[IdentifierLabelResourceData::class]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Unlisted classes render an identifier');

        $this->assertIdentifierLabelRatchet();
    }

    public function test_a_stale_entry_fails_so_the_list_only_shrinks(): void
    {
        $this->ratchet = $this->everythingFound() + [IdentifierLabelRawEnum::class => 'APP-09'];

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Stale entries');

        $this->assertIdentifierLabelRatchet();
    }

    public function test_an_exact_match_passes(): void
    {
        $this->ratchet = $this->everythingFound();

        $this->assertIdentifierLabelRatchet();
    }
}
