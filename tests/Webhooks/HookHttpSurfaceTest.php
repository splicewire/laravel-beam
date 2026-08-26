<?php

namespace Splicewire\Beam\Tests\Webhooks;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
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
use Splicewire\Beam\Webhooks\Http\HookSubscriptionController;
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

        Route::get('hooks/events', [HookEventCatalogController::class, 'index'])->name('hooks.events');
        Route::get('{resource}/hooks/events', [HookEventCatalogController::class, 'index'])->name('resources.hooks.events');
        Route::post('hooks', [HookSubscriptionController::class, 'store'])->name('hooks.subscribe');
        Route::post('{resource}/hooks', [HookSubscriptionController::class, 'store'])->name('resources.hooks.subscribe');
        Route::get('hooks/{hook}/deliveries', [HookDeliveriesController::class, 'index'])->name('hooks.deliveries');
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

    public function test_a_resource_this_host_never_heard_of_is_an_empty_catalog_not_a_404(): void
    {
        $body = $this->getJson('/unicorns/hooks/events')->assertOk()->json('data');

        $this->assertSame('unicorns', $body['resource']);
        $this->assertSame([], $body['events']);
    }

    // ── POST /hooks — reveal-once create ────────────────────────────────────────────────────────

    public function test_the_secret_is_revealed_by_create_and_by_nothing_else_ever(): void
    {
        Bus::fake();

        $created = $this->postJson('/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['tenants.provisioned'],
        ])->assertCreated()->json('data');

        $this->assertNotEmpty($created['secret']);

        $hook = Hook::query()->findOrFail($created['hook']['id']);
        $this->assertSame($created['secret'], $hook->secret);

        // The read projection every other exposure shares carries a preview and no more.
        $projected = HookData::project($hook)->toArray();
        $this->assertArrayNotHasKey('secret', $projected);
        $this->assertNotSame($created['secret'], $projected['secret_preview']);
    }

    public function test_create_queues_the_verification_ping_and_leaves_the_hook_unverified_until_it_answers(): void
    {
        Bus::fake();

        $created = $this->postJson('/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['tenants.provisioned'],
        ])->assertCreated()->json('data');

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

        $this->postJson('/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['tenants.exploded'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.events.0', fn (string $m) => str_contains($m, 'tenants.exploded'));

        $this->assertSame(0, Hook::query()->count());
    }

    public function test_the_scoped_exposure_prefills_the_prefix_and_refuses_a_foreign_event(): void
    {
        Bus::fake();

        $created = $this->postJson('/compositions/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
        ])->assertCreated()->json('data');

        $this->assertSame(['compositions.render.completed'], $created['hook']['events']);

        $this->postJson('/compositions/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['tenants.provisioned'],
        ])->assertStatus(422);
    }

    public function test_a_half_supplied_subject_pair_is_refused_rather_than_read_as_no_subject(): void
    {
        Bus::fake();

        $this->postJson('/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['tenants.provisioned'],
            'subject_type' => 'tenant',
        ])->assertStatus(422);
    }

    public function test_the_entitlement_snapshot_is_taken_off_the_route_the_request_arrived_through(): void
    {
        Bus::fake();

        // Deliberately NOT `gated/hooks`: the scoped exposure `{resource}/hooks` is already mounted and
        // would swallow it, reading "gated" as a resource key. That collision is real at a host too,
        // which is why the scoped mount belongs under a constrained prefix rather than at the root.
        // A pass-through stand-in for the commerce middleware: the snapshot is read off the route's
        // DECLARED middleware string, so what the alias resolves to is irrelevant to what is asserted —
        // and beam core deliberately does not ship the commerce gate (13 §8).
        Route::aliasMiddleware('entitlement', PassThroughEntitlement::class);

        Route::post('subscribe-gated', [HookSubscriptionController::class, 'store'])
            ->middleware('entitlement:composition-engine');

        $created = $this->postJson('/subscribe-gated', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['tenants.provisioned'],
        ])->assertCreated()->json('data');

        $this->assertSame(
            ['composition-engine'],
            Hook::query()->findOrFail($created['hook']['id'])->entitlement_keys,
        );
    }

    public function test_an_ungated_route_snapshots_the_empty_set_which_passes_the_feature_plane_trivially(): void
    {
        Bus::fake();

        $created = $this->postJson('/hooks', [
            'endpoint' => 'https://receiver.test/inbox',
            'events' => ['tenants.provisioned'],
        ])->assertCreated()->json('data');

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

        $route = Route::getRoutes()->getByName('hooks.op.reset');

        $this->assertNotNull($route, 'op/reset must mount through Particle::ops so the name is DERIVED.');
        $this->assertSame('hooks/{id}/op/reset', $route->uri());
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
