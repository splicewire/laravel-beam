<?php

namespace Splicewire\Beam\Tests\Webhooks;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\Frame\Http\Controllers\FrameResourceController;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Events\EventType;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Particle\Ops\ResetHookOp;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Webhooks\DispatchWebhookJob;
use Splicewire\Beam\Webhooks\HookEmitter;
use Splicewire\Beam\Webhooks\Http\HookDeliveriesController;
use Splicewire\Beam\Webhooks\Http\HookEventCatalogController;
use Splicewire\Beam\Webhooks\WebhookDelivery;

/**
 * api-surface-coherence ticket 38, second half — the HTTP surface ticket 12 §1/§3/§6 decided, and the
 * health loop that makes `verified_at` / `disabled_at` mean anything.
 *
 * The first half (record, signature, envelope, subject pruning) is {@see HookSurfaceTest}.
 *
 * ## What each group is actually pinning
 *
 * - **The catalog endpoint** must READ ticket 40's registry and never keep a list. The test that
 *   proves this is the one asking for a resource this host has never heard of: it answers `200` with
 *   an empty catalog, because ticket 91's rule is that a check whose answer depends on the host must
 *   not throw — and a 404 there would be that same fatality wearing a politer status code.
 * - **Reveal-once** is asserted from BOTH ends: the create response carries the secret, and the read
 *   projection of the SAME record does not. One assertion without the other is satisfiable by a bug.
 * - **The health loop** is asserted through `failed()` rather than `handle()`, because that is the
 *   whole decision: `consecutive_failures` counts failed DELIVERIES, and counting attempts would
 *   auto-disable a hook inside a single retry ladder.
 */
class HookHttpSurfaceTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('app.key', str_repeat('h', 32));
    }

    protected function getPackageProviders($app): array
    {
        return [FrameServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $migration = require __DIR__.'/../../database/migrations/shared/create_beam_hooks_table.php.stub';
        $migration->up();

        $this->actingAs(new HookHttpUser);
        Gate::before(fn () => true);

        $catalog = $this->app->make(EventTypeRegistry::class);
        $catalog->register(new EventType(name: 'tenants.provisioned', subjectless: true, description: 'A tenant finished provisioning.'));
        $catalog->register(new EventType(name: 'compositions.render.completed', subjectless: true, description: 'A render finished.'));

        // The unscoped root catalog DECLARES its resource-lessness (api-surface-coherence 106) rather
        // than being recognised by the absence of a stamp.
        Route::get('hooks/events', [HookEventCatalogController::class, 'index'])
            ->defaults(HookEventCatalogController::CONFIG, ['resource' => null])
            ->name('hooks.events');

        // The scoped exposure was ONE wildcard `{resource}/hooks/events` reading its resource from a
        // path parameter until 106 (41 D7) replaced it with concrete per-resource mounts off the
        // `_particle` stamp — mounted here through the real driver, not hand-written, so this test
        // exercises what the estate actually ships. `unicorns` is mounted with NO registered events on
        // purpose: it is what proves an eventless resource answers with an empty catalog, not an error.
        Particle::hookEvents(resource: 'compositions', at: 'compositions');
        Particle::hookEvents(resource: 'unicorns', at: 'unicorns');
        Route::get('hooks/{hook}/deliveries', [HookDeliveriesController::class, 'index'])->name('hooks.deliveries');
    }

    public function test_paused_canonical_creation_mints_secret_without_dispatch(): void
    {
        Bus::fake();
        $created = $this->postJson('/frame/resources/hooks', [
            'endpoint' => 'https://receiver.test/inbox', 'events' => ['tenants.provisioned'], 'paused' => true,
        ])->assertOk()->json('data');
        $this->assertNotEmpty($created['secret']);
        $this->assertFalse($created['pinged']);
        $this->assertNotNull($created['hook']['paused_at']);
        $this->assertSame($created['secret'], Hook::findOrFail($created['hook']['id'])->secret);
        Bus::assertNothingDispatched();
    }

    private function hook(array $attributes = []): Hook
    {
        return Hook::create(array_merge([
            'endpoint' => 'https://receiver.test/hooks',
            'secret' => Hook::mintSecret(),
            'events' => ['tenants.provisioned'],
        ], $attributes));
    }

    // ── GET /hooks/events, at both exposures (12 §3) ────────────────────────────────────────────

    public function test_the_root_catalog_projects_every_registered_event_type(): void
    {
        $body = $this->getJson('/hooks/events')->assertOk()->json('data');

        $this->assertNull($body['resource']);
        $this->assertContains('tenants.provisioned', array_column($body['events'], 'name'));
        $this->assertContains('compositions.render.completed', array_column($body['events'], 'name'));

        // The two lifecycle names beam core registers for itself (12 §8) are in the same catalog —
        // proving this reads the registry rather than an endpoint-local list.
        $this->assertContains('hooks.disabled', array_column($body['events'], 'name'));
    }

    public function test_the_scoped_catalog_filters_by_resource_key_segment_wise(): void
    {
        $body = $this->getJson('/compositions/hooks/events')->assertOk()->json('data');

        $this->assertSame('compositions', $body['resource']);
        $this->assertSame(['compositions.render.completed'], array_column($body['events'], 'name'));
    }

    public function test_the_split_between_resource_and_verb_phrase_is_published_not_left_to_clients(): void
    {
        $entry = collect($this->getJson('/hooks/events')->json('data.events'))
            ->firstWhere('name', 'compositions.render.completed');

        $this->assertSame('compositions', $entry['resource']);
        $this->assertSame('render.completed', $entry['verbPhrase']);
    }

    /**
     * A MOUNTED resource with nothing in the catalog answers empty, not with an error (ticket 91: a
     * check whose answer depends on the host must not be fatal). Post-106 an entirely unknown key has
     * no route at all and is an ordinary 404 — the honest answer for a URL that does not exist, and a
     * different question from this one.
     */
    public function test_a_resource_with_no_registered_events_is_an_empty_catalog_not_an_error(): void
    {
        $body = $this->getJson('/unicorns/hooks/events')->assertOk()->json('data');

        $this->assertSame('unicorns', $body['resource']);
        $this->assertSame([], $body['events']);
    }

    // ── POST /hooks — reveal-once create ────────────────────────────────────────────────────────

    public function test_the_secret_is_revealed_by_create_and_by_nothing_else_ever(): void
    {
        Bus::fake();

        $created = $this->postJson('/frame/resources/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['tenants.provisioned'],
        ])->assertOk()->json('data');

        $this->assertNotEmpty($created['secret']);

        $hook = Hook::query()->findOrFail($created['hook']['id']);
        $this->assertSame($created['secret'], $hook->secret);

        // The read projection every other exposure shares carries a preview and no more.
        $projected = HookData::project($hook)->toArray();
        $this->assertArrayNotHasKey('secret', $projected);
        $this->assertNotSame($created['secret'], $projected['secret_preview']);
        $id = $hook->getKey();
        $read = $this->getJson("/frame/resources/hooks/records/{$id}")->assertOk()->json('data');
        $updated = $this->putJson("/frame/resources/hooks/records/{$id}", ['paused' => true])->assertOk()->json('data');
        $listed = $this->getJson('/frame/resources/hooks')->assertOk()->json('data.0');
        foreach ([$read, $updated, $listed] as $payload) {
            $this->assertArrayNotHasKey('secret', $payload);
            $this->assertArrayNotHasKey('token', $payload);
            $this->assertStringNotContainsString($created['secret'], json_encode($payload));
        }
        $this->assertSame($created['secret'], $hook->refresh()->secret);

    }

    public function test_create_queues_the_verification_ping_and_leaves_the_hook_unverified_until_it_answers(): void
    {
        Bus::fake();

        $created = $this->postJson('/frame/resources/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['tenants.provisioned'],
        ])->assertOk()->json('data');

        $this->assertTrue($created['pinged']);
        $this->assertNull($created['hook']['verified_at']);

        Bus::assertDispatched(
            DispatchWebhookJob::class,
            fn (DispatchWebhookJob $job) => $job->delivery->event === 'hooks.ping',
        );
    }

    public function test_an_event_outside_the_catalog_is_a_422_naming_the_legal_ones(): void
    {
        Bus::fake();

        $this->postJson('/frame/resources/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['tenants.exploded'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.events.0', fn (string $m) => str_contains($m, 'tenants.exploded'));

        $this->assertSame(0, Hook::query()->count());
    }

    public function test_old_subscription_post_surfaces_are_absent(): void
    {
        $this->postJson('/hooks', [])->assertNotFound();
        $this->postJson('/compositions/hooks', [])->assertNotFound();
        $this->assertFalse(class_exists('Splicewire\\Beam\\Webhooks\\Http\\HookSubscriptionController'));
        $this->assertSame(0, Hook::count());
    }

    public function test_creation_requires_explicit_events_and_endpoint(): void
    {
        Bus::fake();
        foreach ([[], ['events' => []], ['events' => null]] as $payload) {
            $this->postJson('/frame/resources/hooks', ['endpoint' => 'https://receiver.test/inbox', ...$payload])
                ->assertUnprocessable()->assertJsonValidationErrors('events');
        }
        foreach ([[], ['endpoint' => null], ['endpoint' => '']] as $payload) {
            $this->postJson('/frame/resources/hooks', ['events' => ['tenants.provisioned'], ...$payload])
                ->assertUnprocessable()->assertJsonValidationErrors('endpoint');
        }
        $this->assertSame(0, Hook::count());
        Bus::assertNothingDispatched();
    }

    public function test_queue_failure_preserves_the_created_subscription_and_reveals_its_secret(): void
    {
        $this->mock(HookEmitter::class)->shouldReceive('ping')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        $created = $this->postJson('/frame/resources/hooks', [
            'endpoint' => 'https://receiver.test/inbox', 'events' => ['tenants.provisioned'],
        ])->assertOk()->json('data');
        $this->assertFalse($created['pinged']);
        $this->assertNotEmpty($created['secret']);
        $this->assertSame($created['secret'], Hook::findOrFail($created['hook']['id'])->secret);
    }

    public function test_a_half_supplied_subject_pair_is_refused_rather_than_read_as_no_subject(): void
    {
        Bus::fake();

        $this->postJson('/frame/resources/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['tenants.provisioned'],
            'subject_type' => 'tenant',
        ])->assertStatus(422);
    }

    public function test_the_entitlement_snapshot_is_taken_off_the_route_the_request_arrived_through(): void
    {
        Bus::fake();

        // The snapshot reads the actual route middleware; this fixture does not implement commerce.
        Route::aliasMiddleware('entitlement', PassThroughEntitlement::class);

        Route::post('gated/resources/{resource}', [FrameResourceController::class, 'store'])
            ->middleware('entitlement:composition-engine');

        $created = $this->postJson('/gated/resources/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['tenants.provisioned'],
        ])->assertOk()->json('data');

        $this->assertSame(
            ['composition-engine'],
            Hook::query()->findOrFail($created['hook']['id'])->entitlement_keys,
        );
    }

    public function test_an_ungated_route_snapshots_the_empty_set_which_passes_the_feature_plane_trivially(): void
    {
        Bus::fake();

        $created = $this->postJson('/frame/resources/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['tenants.provisioned'],
        ])->assertOk()->json('data');

        $hook = Hook::query()->findOrFail($created['hook']['id']);

        $this->assertSame([], $hook->entitlement_keys);
        $this->assertTrue($this->app->make(HookEmitter::class)->entitled($hook));
    }

    // ── GET /hooks/{hook}/deliveries (12 §6) ────────────────────────────────────────────────────

    public function test_the_deliveries_read_answers_empty_rather_than_500_when_the_log_table_is_absent(): void
    {
        $hook = $this->hook();

        $this->getJson("/hooks/{$hook->id}/deliveries")
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    // ── The health loop ─────────────────────────────────────────────────────────────────────────

    public function test_a_failure_counts_once_per_delivery_not_once_per_attempt(): void
    {
        $hook = $this->hook();

        $job = new DispatchWebhookJob(new WebhookDelivery(
            event: 'tenants.provisioned',
            payload: [],
            endpoint: $hook->endpoint,
            secret: $hook->secret,
            hookId: (string) $hook->id,
        ));

        $job->failed(new \RuntimeException('receiver down'));

        $this->assertSame(1, $hook->refresh()->consecutive_failures);
        $this->assertNull($hook->disabled_at, 'One failed delivery must not auto-disable.');
    }

    public function test_crossing_the_threshold_disables_and_the_delivery_id_is_kept_as_the_correlation(): void
    {
        Bus::fake();

        $hook = $this->hook(['consecutive_failures' => Hook::failureThreshold() - 1]);

        $delivery = new WebhookDelivery(
            event: 'tenants.provisioned',
            payload: [],
            endpoint: $hook->endpoint,
            secret: $hook->secret,
            hookId: (string) $hook->id,
        );

        (new DispatchWebhookJob($delivery))->failed(new \RuntimeException('receiver down'));

        $hook->refresh();

        $this->assertNotNull($hook->disabled_at);
        $this->assertSame($delivery->deliveryId(), $hook->last_failure_request_log_id);
    }

    public function test_a_ping_that_answers_2xx_verifies_the_hook_exactly_once(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $hook = $this->hook();

        $job = new DispatchWebhookJob(new WebhookDelivery(
            event: 'hooks.ping',
            payload: [],
            endpoint: $hook->endpoint,
            secret: $hook->secret,
            hookId: (string) $hook->id,
        ));

        $job->handle();
        $verifiedAt = $hook->refresh()->verified_at;

        $this->assertNotNull($verifiedAt);

        // A second ping must not re-stamp: "when was this verified" has to stay answerable.
        $job->handle();
        $this->assertTrue($verifiedAt->equalTo($hook->refresh()->verified_at));
    }

    public function test_a_success_zeroes_the_failure_counter(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $hook = $this->hook(['consecutive_failures' => 3]);

        (new DispatchWebhookJob(new WebhookDelivery(
            event: 'tenants.provisioned',
            payload: [],
            endpoint: $hook->endpoint,
            secret: $hook->secret,
            hookId: (string) $hook->id,
        )))->handle();

        $this->assertSame(0, $hook->refresh()->consecutive_failures);
    }

    public function test_the_delivery_uuid_rides_out_as_the_correlation_header_so_the_log_row_can_be_joined(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $hook = $this->hook();
        $delivery = new WebhookDelivery(
            event: 'tenants.provisioned',
            payload: [],
            endpoint: $hook->endpoint,
            secret: $hook->secret,
            hookId: (string) $hook->id,
        );

        (new DispatchWebhookJob($delivery))->handle();

        Http::assertSent(fn ($request) => $request->header('X-Request-Id')[0] === $delivery->deliveryId());
    }

    /** The mount that keeps `op/reset` out of the flat, last-wins name space. */
    public function test_reset_mounts_through_particle_ops_and_derives_its_route_name(): void
    {
        Particle::ops('hooks', 'hooks', [ResetHookOp::class]);
        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('hooks.reset');

        $this->assertNotNull($route, 'op/reset must mount through Particle::ops so the name is DERIVED.');
        $this->assertSame('hooks/{id}/reset', $route->uri());
    }
}

class PassThroughEntitlement
{
    public function handle($request, $next, ...$keys)
    {
        return $next($request);
    }
}

class HookHttpUser extends User
{
    protected $table = 'users';

    public function getKey()
    {
        return 1;
    }
}
