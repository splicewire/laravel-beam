<?php

namespace Splicewire\Beam\Tests\Intake;

use ReflectionMethod;
use Rushing\LaravelDataSchemasScribe\Attributes\RequestFromData;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Http\PublicIntakeController;
use Splicewire\Beam\Intake\Data\PublicIntakeAcceptedData;
use Splicewire\Beam\Intake\Data\PublicIntakeErrorData;
use Splicewire\Beam\Intake\Data\PublicIntakeSubmissionData;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-facade ticket 124 — the generic intake door crossed a wire boundary and declared nothing.
 *
 * Particle doctrine's invariant admits three declaration sites and no fourth; this door reaches for
 * the THIRD (`#[RequestFromData]`/`#[ResponseFromData]`) because it cannot be a `#[ParticleOp]` — an
 * op's `input:` is a class-string resolved at boot, and this door's input is a JSON Schema resolved
 * per request from a slug. Ticket 89 ruled that and this test is what keeps it ruled: without the
 * attributes the door falls back into `UndeclaredSurfaceAudit`'s negative space, and without the
 * 422 declaration the shape a client actually programs against goes unpublished.
 */
class PublicIntakeDeclarationTest extends TestCase
{
    private function attributes(string $attribute): array
    {
        return (new ReflectionMethod(PublicIntakeController::class, '__invoke'))
            ->getAttributes($attribute);
    }

    public function test_the_door_declares_its_request_shape(): void
    {
        $declared = $this->attributes(RequestFromData::class);

        $this->assertCount(1, $declared, 'The intake door declares no request shape.');
        $this->assertSame(PublicIntakeSubmissionData::class, $declared[0]->newInstance()->dataClass);
    }

    public function test_the_door_declares_both_the_success_and_the_error_shape(): void
    {
        $byStatus = [];
        foreach ($this->attributes(ResponseFromData::class) as $attribute) {
            $instance = $attribute->newInstance();
            $byStatus[$instance->status] = $instance->dataClass;
        }

        $this->assertSame(PublicIntakeAcceptedData::class, $byStatus[201] ?? null);
        $this->assertSame(
            PublicIntakeErrorData::class,
            $byStatus[422] ?? null,
            'The 422 is the shape a client programs against; declaring only the 201 leaves it unpublished.',
        );
    }

    /**
     * The request shape is declared OPEN, and that is the contract. A property declared here would be
     * a field the door forces onto every host's intake schema — which is the contradiction ticket 124
     * exists to avoid: a host must be able to mount an intake surface with no PHP class of its own.
     */
    public function test_the_request_shape_declares_no_fixed_properties(): void
    {
        $this->assertSame(
            [],
            PublicIntakeSubmissionData::empty(),
            'The intake request shape fixed a field. Its field list belongs to the per-request JSON Schema.',
        );
    }

    /** The bodies the controller actually emits are the declared shapes, not hand-built arrays. */
    public function test_the_declared_shapes_match_the_bodies_the_door_emits(): void
    {
        $this->assertSame(
            ['id', 'schemaRef'],
            array_keys((new PublicIntakeAcceptedData(id: 'x', schemaRef: 'y'))->toArray()),
        );

        $this->assertSame(
            ['message', 'errors'],
            array_keys((new PublicIntakeErrorData(message: 'm', errors: ['/a' => ['bad']]))->toArray()),
        );
    }
}
