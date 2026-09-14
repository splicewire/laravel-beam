<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\Delivery\DeliveryResolvers;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Rendering\DeclaresDelivery;
use Splicewire\Beam\Tests\TestCase;

/**
 * `delivery:` on the operation declaration — particle-operation-surface 11 (the decision) and 14 (this
 * landing).
 *
 * ## Why this file asserts a CARRIER and not just a slot
 *
 * The session that landed `method:`, `idConstraint:` and `signed:` deliberately REFUSED this slot, and
 * its reason is the whole shape of this test file: `delivery:` had no consumer. Added alone it would
 * have been the ninth instance of this effort's most-repeated defect — *a declaration that nominates
 * and nothing authorizes* — and ticket 14's acceptance #4 (*"format validation is byte-identical; the
 * OpenAPI 200 still names the same media types"*) would have passed **vacuously**, because nothing
 * would have published a media type to compare.
 *
 * Runtime enforcement remains testable without documentation installed. Publication assertions live
 * in the optional documentation package.
 *
 * ## The assertions are written against the DECLARATION, never against the end state
 *
 * The failure mode this file has to avoid is the one {@see DeclaredRouteFactsTest} names: an assertion
 * that would pass identically against the code before the change. Nothing here reads a value the
 * fixture could have produced on its own — every media type, every 422 and every enum is traced to a
 * `delivery:` slot that did not exist yesterday, and the undeclared case is asserted to be
 * byte-identical to today rather than merely "fine".
 */
class OperationDeliveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('dossiers', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });

        Dossier::create(['label' => 'one']);

        FixtureDocumentDelivery::$formats = ['pdf', 'html'];

        // The Scribe strategies resolve the registry out of the container; without the singleton a
        // `register()` here and the strategy's read land on two different instances and every
        // publication assertion below silently measures an empty registry.
        $this->app->singleton(ParticleOperationRegistry::class);
    }

    // ── the declaration reaches the runtime object ──────────────────────────────────────────────────

    public function test_the_attribute_forwards_delivery_to_the_runtime_operation(): void
    {
        $this->discovery()->registerClass(FixtureDeliveringOp::class);

        $operation = $this->app->make(ParticleOperationRegistry::class)->get('dossiers', 'export');

        // The `signed:` lesson, applied before it can be repeated: a slot that reaches the runtime
        // object as its default is indistinguishable from one that was never declared, and the
        // container reports it as a WRONG ANSWER rather than as an error. A forwarding assertion is
        // the only instrument that can tell "cannot declare a delivery" from "declared none".
        $this->assertSame(FixtureDocumentDelivery::class, $operation->delivery);
    }

    public function test_an_attribute_declaring_no_delivery_takes_the_null_that_preserves_todays_behaviour(): void
    {
        $this->discovery()->registerClass(FixtureBareDeliveryOp::class);

        $operation = $this->app->make(ParticleOperationRegistry::class)->get('dossiers', 'touch');

        $this->assertNull($operation->delivery);
        $this->assertSame([], $operation->formats());
        $this->assertNull(DeliveryResolvers::contract($operation));
    }

    // ── the resolver normalises the slot, and refuses exactly one thing ─────────────────────────────

    public function test_an_instance_is_used_as_is_and_a_class_string_is_container_resolved(): void
    {
        $instance = new FixtureDocumentDelivery;

        $this->assertSame($instance, DeliveryResolvers::for($this->operation('a', delivery: $instance)));
        $this->assertInstanceOf(
            FixtureDocumentDelivery::class,
            DeliveryResolvers::for($this->operation('b', delivery: FixtureDocumentDelivery::class)),
        );
    }

    public function test_a_class_that_is_not_the_port_throws_and_names_itself(): void
    {
        // A non-match here is GRAMMAR — a typo or a wrong import, which the declaration's author could
        // have gotten right without knowing which host would load it. That is this estate's stated test
        // for what may be fatal, and it is why this throws while the id-constraint key-type check (whose
        // answer is a fact about the host's model) is an advisory audit instead.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(Dossier::class);

        DeliveryResolvers::for($this->operation('c', delivery: Dossier::class));
    }

    // ── `?format` becomes a FRAMEWORK parameter, which is ticket 95's bite pre-empted ───────────────

    public function test_a_format_axis_makes_format_a_framework_parameter_and_no_axis_leaves_it_out(): void
    {
        $this->assertSame(
            ['format'],
            $this->operation('d', delivery: FixtureDocumentDelivery::class)->frameworkParameters(),
        );

        $this->assertSame([], $this->operation('e')->frameworkParameters());

        FixtureDocumentDelivery::$formats = [];

        $this->assertSame(
            [],
            $this->operation('f', delivery: FixtureDocumentDelivery::class)->frameworkParameters(),
            'A delivery with one representation has no format axis, so there is no parameter to forgive '
                .'and none to publish — the same call the rendering surface made before ticket 13 deleted it.',
        );
    }

    // ── enforcement: the controller refuses an unlisted format before the handler runs ──────────────

    public function test_an_unlisted_format_is_refused_with_a_422_on_format(): void
    {
        $this->mount($this->operation('export', delivery: FixtureDocumentDelivery::class));

        $this->getJson('/dossiers/1/export?format=docx')
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');
    }

    public function test_a_listed_format_reaches_the_handler(): void
    {
        $this->mount($this->operation('export', delivery: FixtureDocumentDelivery::class));

        $this->getJson('/dossiers/1/export?format=pdf')->assertOk()->assertJson(['ran' => true]);
    }

    public function test_the_rejected_value_is_not_echoed_back(): void
    {
        // A reflected-input smell, and the accepted set is the more useful half of the message anyway.
        // Carried over verbatim from `RenderingsController::format()` rather than re-decided.
        $this->mount($this->operation('export', delivery: FixtureDocumentDelivery::class));

        $body = $this->getJson('/dossiers/1/export?format=%3Cscript%3E')->json();

        $this->assertStringNotContainsString('script', json_encode($body));
    }

    public function test_an_operation_declaring_input_false_still_accepts_the_frameworks_own_format(): void
    {
        // Ticket 95's bite, one slot over, caught by looking rather than by being bitten: `?format` is
        // beam's parameter — the controller reads and validates it — so `rejectInput()` would have
        // 422'd every format request on an `input: false` op had `frameworkParameters()` not been
        // widened in the same change. `media.download` is `input: false` and is exactly this shape.
        $this->mount($this->operation(
            'export',
            delivery: FixtureDocumentDelivery::class,
            input: false,
        ));

        $this->getJson('/dossiers/1/export?format=pdf')->assertOk();
    }

    public function test_an_operation_declaring_no_delivery_validates_nothing_and_is_untouched(): void
    {
        // The whole default. Every declaration in the estate is in this state, so the widening has to be
        // one-directional by construction: `null` is SILENCE, never a stop.
        $this->mount($this->operation('export'));

        $this->getJson('/dossiers/1/export?format=anything-at-all')->assertOk();
    }

    public function test_a_delivery_with_no_format_axis_validates_nothing_either(): void
    {
        // `media.download`'s shape: one representation, no `?format` knob. Refusing a parameter it has
        // never read would be a new behaviour dressed as a fix.
        FixtureDocumentDelivery::$formats = [];

        $this->mount($this->operation('export', delivery: FixtureDocumentDelivery::class));

        $this->getJson('/dossiers/1/export?format=anything-at-all')->assertOk();
    }

    private function discovery(): AttributedParticleDiscovery
    {
        return new AttributedParticleDiscovery(
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
        );
    }

    private function operation(
        string $name,
        DeclaresDelivery|string|null $delivery = null,
        string|false|null $input = null,
    ): ParticleOperation {
        return new ParticleOperation(
            resource: 'dossiers',
            name: $name,
            kind: OperationKind::Read,
            model: Dossier::class,
            handle: fn () => ['ran' => true],
            ability: false,
            input: $input,
            method: null,
            delivery: $delivery,
        );
    }

    private function mount(ParticleOperation $operation): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register($operation);

        Particle::ops('dossiers', 'dossiers', $operation->name, ['method' => 'get']);
        Route::getRoutes()->refreshNameLookups();
    }
}

/**
 * A delivery whose format enumeration comes from a mutable static, so a test can prove the set is read
 * LIVE at both ends rather than frozen into the route table or into the declaration.
 */
class FixtureDocumentDelivery implements DeclaresDelivery
{
    /** @var list<string> */
    public static array $formats = ['pdf', 'html'];

    public function mediaTypes(): array
    {
        return ['application/pdf', 'text/html'];
    }

    public function deliveryHeaders(): array
    {
        return ['Content-Disposition' => 'The filename this document downloads as.'];
    }

    public function defaultFormat(): ?string
    {
        return 'pdf';
    }

    public function formats(): array
    {
        return static::$formats;
    }
}

#[ParticleOp(
    resource: 'dossiers',
    name: 'export',
    kind: OperationKind::Read,
    model: Dossier::class,
    delivery: FixtureDocumentDelivery::class,
)]
class FixtureDeliveringOp
{
    public static function handle(mixed $model, Request $request, mixed $actor): array
    {
        return [];
    }
}

#[ParticleOp(
    resource: 'dossiers',
    name: 'touch',
    kind: OperationKind::Read,
    model: Dossier::class,
)]
class FixtureBareDeliveryOp
{
    public static function handle(mixed $model, Request $request, mixed $actor): array
    {
        return [];
    }
}

class Dossier extends Model
{
    protected $table = 'dossiers';

    protected $guarded = [];

    public $timestamps = false;
}
