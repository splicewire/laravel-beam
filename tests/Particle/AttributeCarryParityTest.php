<?php

namespace Splicewire\Beam\Tests\Particle;

use ReflectionClass;
use ReflectionNamedType;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Routing\IdConstraint;
use Splicewire\Beam\Tests\TestCase;

/**
 * The attribute→registration CARRY parity guard (fail-closed-guards ticket 01; architecture-pass
 * B1-1). The invariant is the carry site's own: "Every slot on `#[ParticleOp]` must appear in this
 * argument list — the twin declares, this line is the only thing that carries"
 * ({@see AttributedParticleDiscovery}, the `signed:` comment recording the two-day silent gap).
 *
 * Mechanism, in two failing-capable halves per attribute:
 *  1. **Fixture completeness** — reflection walks every promoted constructor parameter and fails,
 *     naming the parameter, if the fixture left it at its default. A NEW attribute parameter
 *     therefore reds this suite the moment it exists, telling its author to extend the fixture
 *     (one line) — which is what makes half 2 able to see it.
 *  2. **Carry** — real discovery runs over the fixture, and every parameter's value must arrive on
 *     the registration object under its own name, through CARRY_EXPECTATIONS for the slots the
 *     registration legitimately reshapes (each entry carries its reason).
 */
class AttributeCarryParityTest extends TestCase
{
    /**
     * Slots the registration reshapes on purpose. Key = attribute param; value = [reason, assert].
     * Keep this list SHORT — an entry here is interface the twin reader must also know.
     *
     * @var array<string, string>
     */
    private const OP_CARRY_NOTES = [
        // none yet: ParticleOperation mirrors all 14 names. The day one diverges, document it here
        // with the mapping a reader needs, or the plain property assertion below fails naming it.
    ];

    private function discovery(): AttributedParticleDiscovery
    {
        return new AttributedParticleDiscovery(
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
        );
    }

    /** @return array<string, mixed> param name => fixture's attribute value */
    private function attributeValues(string $attributeClass, string $fixtureClass): array
    {
        $attributes = (new ReflectionClass($fixtureClass))->getAttributes($attributeClass);
        $this->assertCount(1, $attributes, "fixture {$fixtureClass} must carry exactly one {$attributeClass}");

        $instance = $attributes[0]->newInstance();
        $values = [];

        foreach ((new ReflectionClass($attributeClass))->getConstructor()->getParameters() as $param) {
            if (! $param->isPromoted()) {
                continue; // read via assertKnownNonPromoted(): a ctor-translated slot, pinned below
            }

            $values[$param->getName()] = $instance->{$param->getName()};
        }

        return $values;
    }

    /**
     * A NON-promoted constructor parameter is a transform at the attribute's own door (the value is
     * consumed in the ctor body, never stored under its name). Legitimate — but only when pinned
     * here with its reason, so adding one is a visible decision, not a silent carry hole.
     */
    private function assertKnownNonPromoted(string $attributeClass, array $known): void
    {
        $actual = [];
        foreach ((new ReflectionClass($attributeClass))->getConstructor()->getParameters() as $param) {
            if (! $param->isPromoted()) {
                $actual[] = $param->getName();
            }
        }

        $this->assertSame($known, $actual, sprintf(
            '%s gained or lost a NON-promoted constructor parameter. Promote it so the carry guard '
            .'can see it, or pin it in this test with the transform it performs.',
            $attributeClass,
        ));
    }

    private function assertFixtureSetsEverySlot(string $attributeClass, array $values): void
    {
        foreach ((new ReflectionClass($attributeClass))->getConstructor()->getParameters() as $param) {
            if (! $param->isPromoted()) {
                continue; // pinned by assertKnownNonPromoted(); unreadable off the instance by design
            }

            if (! $param->isDefaultValueAvailable()) {
                continue; // required: the fixture cannot compile without it
            }

            $this->assertNotSame(
                $param->getDefaultValue(),
                $values[$param->getName()],
                sprintf(
                    '%s::$%s is at its default in the carry fixture, so a dropped carry would be '
                    .'invisible ("declared default" and "never carried" read the same). Set it to a '
                    .'valid non-default value in %s — if you just ADDED this parameter, that one '
                    .'fixture line is the whole ask, and the carry assertion below then guards it.',
                    $attributeClass,
                    $param->getName(),
                    self::class,
                ),
            );
        }
    }

    public function test_every_particle_op_slot_is_carried_into_the_registration(): void
    {
        $values = $this->attributeValues(ParticleOp::class, CarryFixtureOp::class);
        $this->assertKnownNonPromoted(ParticleOp::class, []);
        $this->assertFixtureSetsEverySlot(ParticleOp::class, $values);

        $this->discovery()->registerClass(CarryFixtureOp::class);
        $operation = $this->app->make(ParticleOperationRegistry::class)
            ->get($values['resource'], $values['name']);

        foreach ($values as $name => $declared) {
            if (array_key_exists($name, self::OP_CARRY_NOTES)) {
                continue;
            }

            $this->assertTrue(
                property_exists($operation, $name),
                "ParticleOperation no longer exposes \${$name}; carry it, or document the reshape in OP_CARRY_NOTES",
            );
            $this->assertSame(
                $declared,
                $operation->{$name},
                "#[ParticleOp] \${$name} was declared but did not arrive on the registration — the carry list dropped it",
            );
        }
    }

    public function test_every_particle_resource_slot_is_carried_into_the_registration(): void
    {
        $values = $this->attributeValues(ParticleResource::class, CarryFixtureResource::class);
        // `filterable` is the G5 compat shim: accepted, translated in the ctor (the 09-22 removal
        // broke released extensions), never stored under its own name.
        $this->assertKnownNonPromoted(ParticleResource::class, ['filterable']);
        $this->assertFixtureSetsEverySlot(ParticleResource::class, $values);

        $this->discovery()->registerClass(CarryFixtureResource::class);
        $resource = $this->app->make(ParticleResourceRegistry::class)->get($values['key']);

        foreach ($values as $name => $declared) {
            $this->assertTrue(
                property_exists($resource, $name),
                "the resource registration no longer exposes \${$name}; carry it, or add a documented expectation",
            );
            $this->assertSame(
                $declared,
                $resource->{$name},
                "#[ParticleResource] \${$name} was declared but did not arrive on the registration — the carry list dropped it",
            );
        }
    }
}

#[ParticleOp(
    resource: 'carry-fixtures',
    name: 'carry-probe',
    kind: OperationKind::Write,
    model: CarryFixtureModel::class,
    ability: 'carry.run',
    abilityModel: CarryFixtureModel::class,
    input: CarryFixtureInput::class,
    output: CarryFixtureOutput::class,
    subject: CarryFixtureSubject::class,
    signed: true,
    method: HttpMethod::Put,
    idConstraint: IdConstraint::Ulid,
    delivery: 'carry-delivery',
    affordance: 'action',
)]
class CarryFixtureOp
{
    public static function handle(CarryFixtureInput $input): array
    {
        return [];
    }
}

#[ParticleResource(
    key: 'carry-fixture-rows',
    backing: CarryFixtureModel::class,
    data: CarryFixtureOutput::class,
    input: CarryFixtureInput::class,
    includes: ['tags'],
    perPage: 50,
    label: 'Carry fixtures',
    form: 'sheet',
    editData: CarryFixtureInput::class,
    policy: CarryFixturePolicy::class,
    readPolicy: CarryFixturePolicy::class,
    readBoundary: 'global',
    query: CarryFixtureQuery::class,
    group: 'carry',
    icon: 'beaker',
    section: 'ops',
    navOrder: 7,
    routeName: 'carry-fixture-rows.index',
    layout: 'wide',
    readOnly: true,
    deletable: false,
    editable: false,
    showable: false,
    frame: true,
    singularLabel: 'Carry fixture',
    routeKey: 'slug',
    handler: CarryFixtureHandler::class,
    createAffordance: 'none',
    filterProvider: CarryFixtureProvider::class,
    summaryProvider: CarryFixtureProvider::class,
    schemaRef: 'carry/fixture',
    createResultData: CarryFixtureOutput::class,
    filterable: true,
)]
class CarryFixtureResource
{
}

// ── Minimal valid occupants for every class-string slot ──────────────────────────────────────────

class CarryFixtureModel extends \Illuminate\Database\Eloquent\Model
{
}

class CarryFixtureInput extends \Spatie\LaravelData\Data
{
    public function __construct(public ?string $label = null) {}
}

class CarryFixtureOutput extends \Spatie\LaravelData\Data
{
    public function __construct(public ?string $label = null) {}
}

class CarryFixtureSubject implements \Splicewire\Beam\Particle\Subject\ResolvesOperationSubject
{
    public static function pathParameters(): array
    {
        return ['id'];
    }

    public function yieldsSubject(): bool
    {
        return true;
    }

    public function resolve(\Splicewire\Beam\Particle\ParticleOperation $operation, array $parameters, mixed $actor): ?object
    {
        return null;
    }
}

class CarryFixturePolicy implements \Splicewire\Beam\Authorization\ResourceReadPolicy
{
    public function inspect(
        ?\Illuminate\Contracts\Auth\Authenticatable $actor,
        \Splicewire\Beam\Particle\ParticleResource $resource,
        \Illuminate\Http\Request $request,
    ): \Illuminate\Auth\Access\Response {
        return \Illuminate\Auth\Access\Response::allow();
    }
}

class CarryFixtureQuery
{
}

class CarryFixtureHandler
{
}

class CarryFixtureProvider
{
}
