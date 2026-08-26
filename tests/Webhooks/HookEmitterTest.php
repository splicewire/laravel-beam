<?php

namespace Splicewire\Beam\Tests\Webhooks;

use Illuminate\Support\Facades\Bus;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Webhooks\DispatchWebhookJob;
use Splicewire\Beam\Webhooks\HookEmitter;

/**
 * api-surface-coherence ticket 38 — the emission half, and specifically the three rules ticket 13
 * attached to it that are all easy to get backwards:
 *
 *  - a FEATURE-plane failure PAUSES, it does not fail (§4);
 *  - an empty entitlement snapshot passes trivially without consulting the gate at all (§8), which is
 *    what makes a bare beam host work when the null resolver DENIES every key;
 *  - a derivation failure withholds ONE delivery and never aborts the domain event (§5).
 */
class HookEmitterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $migration = require __DIR__.'/../../database/migrations/shared/create_beam_hooks_table.php.stub';
        $migration->up();

        Bus::fake();
    }

    private function hook(array $attributes = []): Hook
    {
        return Hook::create(array_merge([
            'endpoint' => 'https://receiver.test/hooks',
            'secret' => Hook::mintSecret(),
            'events' => ['tenants.provisioned'],
        ], $attributes));
    }

    private function emitter(): HookEmitter
    {
        return $this->app->make(HookEmitter::class);
    }

    public function test_a_bare_host_delivers_because_an_empty_snapshot_never_consults_the_gate(): void
    {
        // The null kernel resolver reports NO entitlements, so anything that asked the gate would deny.
        $this->assertSame([], $this->app->make(EntitlementResolver::class)->entitlementsFor(null));

        $hook = $this->hook();

        $delivered = $this->emitter()->emit('tenants.provisioned', ['tenantId' => 't-1']);

        $this->assertCount(1, $delivered);
        $this->assertNull($hook->fresh()->paused_at);
        Bus::assertDispatched(DispatchWebhookJob::class);
    }

    public function test_a_lapsed_entitlement_pauses_and_does_not_count_toward_auto_disable(): void
    {
        $hook = $this->hook(['entitlement_keys' => ['workbench.enter']]);

        $delivered = $this->emitter()->emit('tenants.provisioned', ['tenantId' => 't-1']);

        $this->assertSame([], $delivered);
        Bus::assertNotDispatched(DispatchWebhookJob::class);

        $fresh = $hook->fresh();
        $this->assertNotNull($fresh->paused_at, 'A lapsed plan PAUSES (13 §4).');
        $this->assertNull($fresh->disabled_at, 'It must never auto-disable — op/reset cannot fix a billing lapse.');
        $this->assertSame(0, $fresh->consecutive_failures, 'Nothing was sent, so nothing failed.');
    }

    public function test_pausing_is_idempotent_and_keeps_the_original_timestamp(): void
    {
        $hook = $this->hook(['entitlement_keys' => ['workbench.enter'], 'paused_at' => now()->subDay()]);
        $original = $hook->fresh()->paused_at;

        $this->emitter()->emit('tenants.provisioned', []);

        // Already paused, so the scope never selected it — and even if it had, the pause would stand.
        $this->assertEquals($original, $hook->fresh()->paused_at);
    }

    public function test_the_delivery_carries_the_hooks_secret_and_id(): void
    {
        $hook = $this->hook();

        $delivery = $this->emitter()->deliveryFor($hook, 'tenants.provisioned', ['a' => 1], now()->toIso8601String());

        $this->assertSame($hook->secret, $delivery->secret);
        $this->assertSame((string) $hook->id, $delivery->hookId);
        $this->assertTrue($delivery->signed());
    }

    public function test_an_unregistered_event_name_finds_no_subscribers_rather_than_throwing(): void
    {
        $this->hook();

        $this->assertSame([], $this->emitter()->emit('nothing.ever.registered.this', []));
    }

    public function test_the_verification_ping_is_dispatched_and_is_not_a_catalog_event(): void
    {
        $hook = $this->hook();

        $this->emitter()->ping($hook);

        Bus::assertDispatched(DispatchWebhookJob::class, function (DispatchWebhookJob $job) use ($hook) {
            return $job->delivery->event === 'hooks.ping'
                && $job->delivery->hookId === (string) $hook->id
                && $job->delivery->signed();
        });

        $this->assertNull(
            $this->app->make(\Splicewire\Beam\Events\EventTypeRegistry::class)->find('hooks.ping'),
            'hooks.ping is delivered but not subscribable — listing it would put a name in GET /hooks/events that no `events` array may legally contain.',
        );
    }
}
