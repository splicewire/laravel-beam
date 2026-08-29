<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Routing\HttpMethod;
use Splicewire\Beam\Routing\IdConstraint;
use Splicewire\Beam\Tests\TestCase;

/**
 * `method:` and `idConstraint:` on the operation declaration (particle-operation-surface 14), plus
 * `signed:` reaching the attribute at last.
 *
 * ## What each of these tests is actually guarding, because none of them is guarding "it works"
 *
 * All three slots are **moves**, not inventions — the verb and the `{id}` constraint were already
 * choosable at the mount, as `Particle::ops(…, ['method' => 'get'])`. So a test that only asserted the
 * end state (`the route is a GET`) would pass identically against the code before this change, because
 * the mount option produced the same route. Every assertion below therefore names the *declaration* as
 * the source and gives the option nothing to say, or sets the two in opposition.
 *
 * The `signed:` case is the sharper one, and it is the reason this file exists at all. That slot was
 * added to {@see ParticleOperation} and to one imperative op, and never to the attribute twin or to
 * {@see AttributedParticleDiscovery} — for two days, invisibly, because an attributed op silently took
 * the runtime default `false`. There was no error to see: *"cannot be signed"* and *"declared
 * unsigned"* read identically off the container. A forwarding test is the only instrument that can
 * tell those two apart, which is why one exists now for every slot on the attribute.
 */
class DeclaredRouteFactsTest extends TestCase
{
    private function discovery(): AttributedParticleDiscovery
    {
        return new AttributedParticleDiscovery(
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
        );
    }

    /**
     * ⚠️ `refreshNameLookups()` is not ceremony. `RouteCollection` builds its name index once and the
     * app has already booted by the time a test mounts anything, so `getByName()` answers **null** for
     * a route that is demonstrably in the collection — a miss that reads exactly like "the mount did
     * not happen" and sends you looking at the mounter.
     */
    private function routeNamed(string $name): \Illuminate\Routing\Route
    {
        Route::getRoutes()->refreshNameLookups();

        return Route::getRoutes()->getByName($name)
            ?? $this->fail("No route named [{$name}] was mounted.");
    }

    // ── the attribute forwards every slot it declares ────────────────────────────────────────────────

    public function test_the_attribute_forwards_signed_method_and_id_constraint_to_the_runtime_operation(): void
    {
        $this->discovery()->registerClass(FixtureDeclaredFactsOp::class);

        $operation = $this->app->make(ParticleOperationRegistry::class)->get('gadgets', 'peek');

        $this->assertTrue(
            $operation->signed,
            'A `#[ParticleOp(signed: true)]` that arrives at the runtime object as `false` is '
                .'indistinguishable from an op that declared nothing — which is exactly how this slot '
                .'stayed unforwarded for two days without a single failing test.',
        );
        $this->assertSame(HttpMethod::Get, $operation->method);
        $this->assertSame(IdConstraint::Uuid, $operation->idConstraint);
    }

    public function test_an_attribute_declaring_none_of_the_three_takes_the_defaults_that_preserve_todays_behaviour(): void
    {
        $this->discovery()->registerClass(FixtureBareOp::class);

        $operation = $this->app->make(ParticleOperationRegistry::class)->get('gadgets', 'poke');

        $this->assertFalse($operation->signed);
        $this->assertNull($operation->method, '`null` must mean POST by short-circuit, not by a default value that has to be kept in step.');
        $this->assertNull($operation->idConstraint);
    }

    // ── the mounter reads the declaration ────────────────────────────────────────────────────────────

    public function test_a_declared_method_decides_the_verb_with_no_mount_option_present(): void
    {
        $this->registerOperation('spin', method: HttpMethod::Get);

        Route::prefix('resources')->group(fn () => Particle::ops('gadgets', 'gadgets', 'spin'));

        $this->assertSame(['GET', 'HEAD'], $this->routeNamed('gadgets.spin')->methods());
    }

    public function test_an_undeclared_method_still_mounts_post(): void
    {
        $this->registerOperation('shove');

        Route::prefix('resources')->group(fn () => Particle::ops('gadgets', 'gadgets', 'shove'));

        $this->assertSame(['POST'], $this->routeNamed('gadgets.shove')->methods());
    }

    public function test_the_declaration_wins_over_a_contradicting_mount_option(): void
    {
        $this->registerOperation('twist', method: HttpMethod::Delete);

        Route::prefix('resources')->group(
            fn () => Particle::ops('gadgets', 'gadgets', 'twist', ['method' => 'get']),
        );

        $this->assertSame(
            ['DELETE'],
            $this->routeNamed('gadgets.twist')->methods(),
            'The point of the slot is that the operation owns its verb. A mount that disagrees is a '
                .'stale call site, and the declaration is the one with the reasoning attached to it.',
        );
    }

    public function test_the_mount_option_still_decides_when_the_declaration_says_nothing(): void
    {
        $this->registerOperation('nudge');

        Route::prefix('resources')->group(
            fn () => Particle::ops('gadgets', 'gadgets', 'nudge', ['method' => 'get']),
        );

        $this->assertSame(
            ['GET', 'HEAD'],
            $this->routeNamed('gadgets.nudge')->methods(),
            'The option arm is a MIGRATION state with a deletion condition, not dead code — one mount '
                .'site (beam-accounts\' signed login-as link, mounted GET) is in a package ticket 14 did '
                .'not own, and dropping this arm early makes that a 405 no suite in the estate would see.',
        );
    }

    public function test_a_declared_uuid_constraint_reaches_the_route_without_a_mount_option(): void
    {
        $this->registerOperation('inspect', idConstraint: IdConstraint::Uuid);

        Route::prefix('resources')->group(fn () => Particle::ops('gadgets', 'gadgets', 'inspect'));

        $this->assertArrayHasKey('id', $this->routeNamed('gadgets.inspect')->wheres);
    }

    public function test_int_and_ulid_are_declared_but_emit_no_route_constraint(): void
    {
        $this->registerOperation('tally', idConstraint: IdConstraint::Int);

        Route::prefix('resources')->group(fn () => Particle::ops('gadgets', 'gadgets', 'tally'));

        $this->assertArrayNotHasKey(
            'id',
            $this->routeNamed('gadgets.tally')->wheres,
            'Ticket 14 gate 3: only `Uuid` enforces today. Enforcing `Int` on the day the enum shipped '
                .'would have 404\'d audiostud\'s whole operator-customer surface, which declared `int` '
                .'against a HasUuids model — behind a green suite.',
        );
    }

    public function test_the_declared_constraint_also_reaches_the_deprecated_alias(): void
    {
        $this->registerOperation('probe', method: HttpMethod::Get, idConstraint: IdConstraint::Uuid);

        Route::prefix('resources')->group(fn () => Particle::ops('gadgets', 'gadgets', 'probe'));

        $alias = $this->routeNamed('gadgets.op.probe');

        $this->assertSame(['GET', 'HEAD'], $alias->methods());
        $this->assertArrayHasKey(
            'id',
            $alias->wheres,
            'The alias is the SAME operation at an older URL. A declared fact that reached only the '
                .'primary would make the deprecated spelling behave differently from the one it is an '
                .'alias of — which is the one thing an alias must never do.',
        );
    }

    private function registerOperation(string $name, ?HttpMethod $method = null, ?IdConstraint $idConstraint = null): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'gadgets',
            name: $name,
            kind: OperationKind::Task,
            model: 'App\\Models\\Gadget',
            handle: fn () => null,
            method: $method,
            idConstraint: $idConstraint,
        ));
    }
}

#[ParticleOp(
    resource: 'gadgets',
    name: 'peek',
    kind: OperationKind::Read,
    model: 'App\\Models\\Gadget',
    signed: true,
    method: HttpMethod::Get,
    idConstraint: IdConstraint::Uuid,
)]
class FixtureDeclaredFactsOp
{
    public static function handle(mixed $model, Request $request, mixed $actor): array
    {
        return [];
    }
}

#[ParticleOp(
    resource: 'gadgets',
    name: 'poke',
    kind: OperationKind::Task,
    model: 'App\\Models\\Gadget',
)]
class FixtureBareOp
{
    public static function handle(mixed $model, Request $request, mixed $actor): array
    {
        return [];
    }
}
