<?php

namespace Splicewire\Beam\Tests\Particle;

use InvalidArgumentException;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Codegen\DeclaredParticleTypes;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * An op's `errors:` slot (music-capability-lift 34/35 prerequisite): the body Data it answers with at a non-success status,
 * declared beside `output:` at the same declaration site, so the spec and the generated clients can type the refusal.
 */
class OperationErrorsTest extends TestCase
{
    public function test_errors_forward_from_attribute_to_runtime(): void
    {
        $ops = app(ParticleOperationRegistry::class);
        (new AttributedParticleDiscovery(app(ParticleResourceRegistry::class), $ops))->registerClass(ErrorsFixtureOperation::class);

        $this->assertSame([422 => ErrorsFixtureRefusalData::class], $ops->find('errors-fixtures', 'dispatch')->errors);
    }

    public function test_an_undeclared_slot_stays_null_and_a_declared_one_keeps_its_statuses(): void
    {
        $this->assertNull($this->op()->errors);
        $this->assertSame(
            [409 => ErrorsFixtureRefusalData::class, 422 => ErrorsFixtureRefusalData::class],
            $this->op([409 => ErrorsFixtureRefusalData::class, 422 => ErrorsFixtureRefusalData::class])->errors,
        );
    }

    public function test_a_success_or_non_http_status_is_refused_at_registration(): void
    {
        foreach ([200, 204, 302, 99, 600] as $status) {
            try {
                $this->op([$status => ErrorsFixtureRefusalData::class]);
                $this->fail("status {$status} was accepted");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('errors-fixtures.dispatch', $e->getMessage());
                $this->assertStringContainsString((string) $status, $e->getMessage());
            }
        }
    }

    public function test_a_body_that_is_not_a_data_class_is_refused_at_registration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('errors-fixtures.dispatch');

        $this->op([422 => \stdClass::class]);
    }

    public function test_declared_error_bodies_are_reachable_types(): void
    {
        $ops = new ParticleOperationRegistry;
        $ops->register($this->op([422 => ErrorsFixtureRefusalData::class]));

        $declared = (new DeclaredParticleTypes(new ParticleResourceRegistry, $ops))->declared();

        $this->assertSame(['operation [errors-fixtures.dispatch] errors: [422]'], $declared[ErrorsFixtureRefusalData::class]);
    }

    /** @param  array<int, string>|null  $errors */
    private function op(?array $errors = null): ParticleOperation
    {
        return new ParticleOperation(
            resource: 'errors-fixtures',
            name: 'dispatch',
            kind: OperationKind::Task,
            handle: fn () => null,
            output: ErrorsFixtureDispatchData::class,
            errors: $errors,
        );
    }
}

class ErrorsFixtureDispatchData extends Data
{
    public function __construct(public string $runId) {}
}

class ErrorsFixtureRefusalData extends Data
{
    public function __construct(public string $code, public string $detail) {}
}

#[ParticleOp(resource: 'errors-fixtures', name: 'dispatch', kind: OperationKind::Task, output: ErrorsFixtureDispatchData::class, errors: [422 => ErrorsFixtureRefusalData::class])]
class ErrorsFixtureOperation
{
    public static function handle(): mixed
    {
        return null;
    }
}
