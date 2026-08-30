<?php

namespace Splicewire\Beam\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\Mount\ParticleMounter;
use Splicewire\Beam\Tests\TestCase;

/**
 * api-surface-coherence ticket 51 §1/§2 — the two undeclared route-table side effects of a relative mount.
 *
 * §1 is the app-global route binding: `Particle::relative(…)` claims a route PARAMETER NAME for the whole
 * application, which means routes the mount never saw change how they resolve. Ticket 51 ruled the claim
 * stays global — Laravel has no scoped explicit binding to move it to — and pays for that two ways: the
 * claim goes through `Router::model()` so it behaves exactly like the implicit binding it displaces, and
 * it is ledgered so it is inspectable and a conflicting re-claim is reported.
 *
 * §2 is the Closure `via:`, which rides the route DEFAULTS and therefore decides whether the table
 * survives `route:cache`. These tests pin the limitation rather than argue about it.
 */
class RelativeBindingClaimTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('vaults', function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
        });

        Schema::create('lockers', function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
        });
    }

    // ---- §1 — the app-global claim -------------------------------------------------------------------

    public function test_a_relative_mount_ledgers_the_app_global_route_parameter_it_claims(): void
    {
        Particle::relative('vaults', Vault::class, via: 'lockers', routes: function (): void {
            Route::get('probe', fn () => 'ok');
        });

        // The whole point of the ledger: an app-global side effect of mounting is now answerable without
        // grepping every route file in a host and its packages.
        $this->assertSame(
            ['vault' => Vault::class],
            $this->app->make(ParticleMounter::class)->bindingClaims(),
        );
    }

    public function test_the_claim_honours_the_models_own_route_key_rather_than_its_primary_key(): void
    {
        // The measured harm in ticket 51 §1. The claim used to be a hand-rolled
        // `fn ($value) => $model::query()->findOrFail($value)` — a PRIMARY KEY lookup — and it displaced
        // Laravel's implicit binding for EVERY `{vault}` route in the app, including hand-written ones the
        // mount never saw. Those routes silently stopped honouring `getRouteKeyName()`.
        Vault::create(['slug' => 'strongbox']);

        Particle::relative('vaults', Vault::class, via: 'lockers', routes: function (): void {
            Route::get('probe', fn () => 'ok');
        });

        // A route the relative mount did NOT write, sharing the parameter name — the concept-anchors shape.
        // `SubstituteBindings` is stated explicitly because a bare Testbench route runs no middleware
        // group, and the binder only fires inside that middleware.
        Route::middleware(SubstituteBindings::class)
            ->get('vaults/{vault}/hand-written', fn (Vault $vault) => $vault->slug);

        $this->get('vaults/strongbox/hand-written')->assertOk()->assertSee('strongbox');

        // …and the primary key, which the old hand-rolled binder accepted, is now correctly a 404.
        $this->get('vaults/1/hand-written')->assertNotFound();
    }

    public function test_reclaiming_one_parameter_for_a_second_model_warns_and_does_not_throw(): void
    {
        // Advisory, never fatal. This runs at boot inside route registration, and a boot-time throw is the
        // shape that took a host down on 2026-08-25 — AGENTS.md carries the rule, ticket 49 recorded it.
        Log::shouldReceive('warning')->once()->withArgs(
            fn (string $message): bool => str_contains($message, '{vault}') && str_contains($message, Locker::class),
        );

        Particle::relative('vaults', Vault::class, via: 'lockers', routes: function (): void {
            Route::get('probe-one', fn () => 'ok');
        });

        Particle::relative('decoys', Locker::class, via: 'lockers', routes: function (): void {
            Route::get('probe-two', fn () => 'ok');
        }, options: ['binding' => 'vault']);

        // The later claim wins the map, which is exactly why it is worth a warning.
        $this->assertSame(
            ['vault' => Locker::class],
            $this->app->make(ParticleMounter::class)->bindingClaims(),
        );
    }

    public function test_reclaiming_one_parameter_for_the_same_model_is_silent(): void
    {
        // One child under two parents of the same class is a legitimate shape, and ticket 50's edge
        // declarations make it the common one. It must not produce noise.
        Log::shouldReceive('warning')->never();

        foreach (['lockers', 'spares'] as $via) {
            Particle::relative('vaults', Vault::class, via: $via, routes: function () use ($via): void {
                Route::get("probe-{$via}", fn () => 'ok');
            });
        }

        $this->assertSame(['vault' => Vault::class], $this->app->make(ParticleMounter::class)->bindingClaims());
    }

    // ---- §2 — what rides the route defaults ----------------------------------------------------------

    public function test_a_relation_name_via_leaves_the_route_table_serializable(): void
    {
        Particle::relative('vaults', Vault::class, via: 'lockers', routes: function (): void {
            Route::get('probe', fn () => 'ok')->name('vaults.probe');
        });

        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('vaults.probe');

        $this->assertSame('lockers', $route->defaults[ParticleController::VIA]);

        // The property `route:cache` actually needs: the defaults array survives a round trip through
        // PHP's own serializer. The sibling rendering mount's docblock stated this rule for its own
        // per-route config; the relative mount is the one place that could break it.
        $this->assertSame($route->defaults, unserialize(serialize($route->defaults)));
    }

    public function test_a_closure_via_makes_the_route_table_uncacheable(): void
    {
        // Documented limitation, not a supported shape (ticket 51 §2). Ticket 50's `#[ParticleRelative]`
        // is where an edge that genuinely needs behaviour puts it — a `public static` convention method,
        // whose route default is the edge CLASS NAME and therefore a serializable reference.
        Particle::relative('vaults', Vault::class, via: fn ($query) => $query, routes: function (): void {
            Route::get('probe', fn () => 'ok')->name('vaults.closure-probe');
        });

        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('vaults.closure-probe');

        $this->expectException(\Exception::class);

        serialize($route->defaults);
    }
}

class Vault extends Model
{
    protected $table = 'vaults';

    public $timestamps = false;

    protected $fillable = ['slug'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class Locker extends Model
{
    protected $table = 'lockers';

    public $timestamps = false;

    protected $fillable = ['slug'];
}
