<?php

namespace Splicewire\Beam\Tests\Webhooks;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Data\HookData;
use Splicewire\Beam\Data\HookInputData;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Events\HookEventRegistrar;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Models\Hook;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Particle\Ops\ResetHookOp;
use Splicewire\Beam\Tests\TestCase;
use Splicewire\Beam\Webhooks\DispatchWebhookJob;
use Splicewire\Beam\Webhooks\HookDeliveryEnvelope;
use Splicewire\Beam\Webhooks\HookSignature;
use Splicewire\Beam\Webhooks\WebhookDelivery;

/**
 * api-surface-coherence ticket 38 — the `hooks` particle resource and the signed delivery edge.
 *
 * The signature assertions are the load-bearing ones. Two of them are written the awkward way on
 * purpose: `test_the_signature_covers_the_exact_bytes_sent` re-reads the body off the FAKED request
 * rather than re-encoding the envelope, because a test that signs and verifies through the same
 * encoder cannot see the classic HMAC-webhook bug (signing one serialization and sending another),
 * and `test_a_tampered_body_fails_verification` flips one byte rather than trusting a happy path.
 */
class HookSurfaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Run the shipped migration stub itself rather than a hand-written copy — the table under test
        // is then the table hosts actually get, and a drift between the two is a failure here.
        $migration = require __DIR__.'/../../database/migrations/shared/create_beam_hooks_table.php.stub';
        $migration->up();
    }

    private function hook(array $attributes = []): Hook
    {
        return Hook::create(array_merge([
            'endpoint' => 'https://receiver.test/hooks',
            'secret' => Hook::mintSecret(),
            'events' => ['tenants.provisioned'],
        ], $attributes));
    }

    // ── The record ──────────────────────────────────────────────────────────────────────────────────

    public function test_the_shipped_migration_creates_the_shared_hooks_table(): void
    {
        $this->assertTrue(Schema::hasTable(Beam::table('hooks')));

        foreach (['endpoint', 'secret', 'token', 'events', 'subject_type', 'subject_id', 'paused_at',
            'disabled_at', 'consecutive_failures', 'last_failure_request_log_id', 'verified_at',
            'entitlement_keys', 'owner_type', 'owner_id'] as $column) {
            $this->assertTrue(
                Schema::hasColumn(Beam::table('hooks'), $column),
                "hooks is missing the [{$column}] column ticket 38 decided.",
            );
        }
    }

    public function test_there_is_no_resource_column_because_the_resource_is_the_name_prefix(): void
    {
        $this->assertFalse(Schema::hasColumn(Beam::table('hooks'), 'resource'));

        $hook = $this->hook(['events' => ['tenants.provisioned', 'compositions.render.completed', 'tenants.suspended']]);

        $this->assertSame(['tenants', 'compositions'], $hook->resourceKeys());
    }

    public function test_paused_and_disabled_are_separate_and_delivery_needs_both_clear(): void
    {
        $hook = $this->hook();
        $this->assertTrue($hook->deliverable());

        $hook->paused_at = now();
        $this->assertFalse($hook->deliverable());

        $hook->paused_at = null;
        $hook->disabled_at = now();
        $this->assertFalse($hook->deliverable());
    }

    public function test_reset_is_the_only_path_out_of_auto_disable_and_leaves_pause_alone(): void
    {
        config()->set('webhooks.outbound.failure_threshold', 3);

        $hook = $this->hook();

        $this->assertFalse($hook->recordFailure('log-1'));
        $this->assertFalse($hook->recordFailure('log-2'));
        $this->assertTrue($hook->recordFailure('log-3'), 'The threshold-crossing failure must report that it disabled the hook.');
        $this->assertFalse($hook->recordFailure('log-4'), 'An already-disabled hook must not re-announce itself on every retry.');

        $hook->paused_at = now();
        $hook->save();

        $hook->reset();

        $this->assertNull($hook->disabled_at);
        $this->assertSame(0, $hook->consecutive_failures);
        $this->assertNull($hook->last_failure_request_log_id);
        $this->assertNotNull($hook->paused_at, 'reset must NOT clear user intent — a lapsed plan comes back when the plan does (13 §4).');
    }

    public function test_any_success_zeroes_the_consecutive_counter(): void
    {
        $hook = $this->hook();
        $hook->recordFailure('log-1');
        $hook->recordFailure('log-2');

        $hook->recordSuccess();

        $this->assertSame(0, $hook->fresh()->consecutive_failures);
    }

    public function test_the_subscribed_scope_matches_the_name_and_honours_the_subject_morph(): void
    {
        $bare = $this->hook();
        $narrowed = $this->hook(['subject_type' => 'tenant', 'subject_id' => 'abc']);
        $other = $this->hook(['subject_type' => 'tenant', 'subject_id' => 'zzz']);
        $paused = $this->hook(['paused_at' => now()]);
        $unrelated = $this->hook(['events' => ['compositions.render.completed']]);

        $ids = Hook::query()->subscribedTo('tenants.provisioned')->pluck('id')->all();

        $this->assertContains($bare->id, $ids);
        $this->assertContains($narrowed->id, $ids);
        $this->assertNotContains($paused->id, $ids, 'A paused hook is not deliverable.');
        $this->assertNotContains($unrelated->id, $ids);
        $this->assertContains($other->id, $ids);
    }

    // ── The declaration ─────────────────────────────────────────────────────────────────────────────

    public function test_the_hooks_resource_declares_both_shape_slots_and_groups_under_platform(): void
    {
        $attribute = (new \ReflectionClass(HookData::class))
            ->getAttributes(ParticleResource::class)[0]->newInstance();

        $this->assertSame('hooks', $attribute->key);
        $this->assertSame(Hook::class, $attribute->backing);
        $this->assertSame(HookData::class, $attribute->data);
        $this->assertSame(HookInputData::class, $attribute->input);
        $this->assertSame('Platform', $attribute->group, '12 §9: a hook is not filed under the resource it is viewed through.');
    }

    public function test_the_read_projection_never_carries_the_secret(): void
    {
        $hook = $this->hook();

        $projected = HookData::project($hook)->toArray();

        $this->assertArrayNotHasKey('secret', $projected);
        $this->assertArrayHasKey('secret_preview', $projected);
        $this->assertStringNotContainsString($hook->secret, json_encode($projected));
    }

    public function test_the_write_dto_refuses_the_columns_only_the_platform_may_set(): void
    {
        $properties = array_map(
            fn (\ReflectionProperty $p) => $p->getName(),
            (new \ReflectionClass(HookInputData::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
        );

        foreach (['secret', 'disabled_at', 'consecutive_failures', 'verified_at', 'entitlement_keys', 'owner_type'] as $forbidden) {
            $this->assertNotContains($forbidden, $properties, "[{$forbidden}] must not be caller-writable.");
        }
    }

    public function test_a_partial_write_does_not_null_the_fields_it_did_not_name(): void
    {
        $attributes = HookInputData::from(['endpoint' => 'https://new.test/hooks'])->toModelAttributes();

        $this->assertSame(['endpoint' => 'https://new.test/hooks'], $attributes);
    }

    public function test_the_caller_supplied_token_can_be_revoked_by_sending_it_null(): void
    {
        // The three states a PATCH body can be in. `token` is the additive bearer the receiver already
        // knows (nullable in `create_beam_hooks_table`, orthogonal to `secret`), so "stop sending an
        // Authorization header" is a real subscriber intent — and on the old `!== null` gate it had no
        // wire representation at all: an omitted field and an explicit null were the same request.
        $absent = HookInputData::from(['endpoint' => 'https://new.test/hooks'])->toModelAttributes();
        $this->assertArrayNotHasKey('token', $absent, 'An omitted field must not be written.');

        $revoked = HookInputData::from(['token' => null])->toModelAttributes();
        $this->assertArrayHasKey('token', $revoked, 'An explicit null must reach the column.');
        $this->assertNull($revoked['token']);

        $set = HookInputData::from(['token' => 'bearer-token'])->toModelAttributes();
        $this->assertSame('bearer-token', $set['token']);
    }

    public function test_the_not_null_columns_stay_on_the_drop_nulls_gate(): void
    {
        // Deliberate non-conversion. `endpoint` and `events` are NOT NULL in the table, so a "clear" on
        // either is a constraint violation dressed up as an API affordance.
        //
        // ⚠️ `subject_type`/`subject_id` USED to be asserted here too, held back on an authorization
        // ground rather than a schema one: clearing them widens a narrowed subscription to the whole
        // resource, and until particle-write-surface ticket 04 the update path re-ran no reach check.
        // 04 landed that check and made it `Optional`-aware, so the pair converted — the clear is now
        // vetted on the subjectless (`viewAny`) plane. See HookSubjectRepointTest's three clear tests.
        $attributes = HookInputData::from([
            'endpoint' => null,
            'events' => null,
        ])->toModelAttributes();

        $this->assertSame([], $attributes);
    }

    public function test_a_subject_is_absent_when_unnamed_and_cleared_when_explicitly_null(): void
    {
        // The three-state pair, at the mapper. The HTTP half — that the clear is AUTHORIZED before it
        // reaches here — is HookSubjectRepointTest's; this pins only that the mapper can express it.
        $absent = HookInputData::from(['endpoint' => 'https://x.test/hooks'])->toModelAttributes();

        $this->assertArrayNotHasKey('subject_type', $absent);
        $this->assertArrayNotHasKey('subject_id', $absent);

        $cleared = HookInputData::from([
            'subject_type' => null,
            'subject_id' => null,
        ])->toModelAttributes();

        $this->assertArrayHasKey('subject_type', $cleared);
        $this->assertNull($cleared['subject_type']);
        $this->assertNull($cleared['subject_id']);
    }

    public function test_pausing_is_a_boolean_on_the_wire_and_a_timestamp_in_the_column(): void
    {
        $this->assertNotNull(HookInputData::from(['paused' => true])->toModelAttributes()['paused_at']);
        $this->assertNull(HookInputData::from(['paused' => false])->toModelAttributes()['paused_at']);
    }

    public function test_the_reset_op_declares_no_input_rather_than_omitting_the_slot(): void
    {
        $attribute = (new \ReflectionClass(ResetHookOp::class))
            ->getAttributes(ParticleOp::class)[0]->newInstance();

        $this->assertSame('hooks', $attribute->resource);
        $this->assertSame('reset', $attribute->name);
        $this->assertFalse($attribute->input, 'ticket 69 reads a MISSING input slot as a defect; reset declares none.');
        $this->assertSame(HookData::class, $attribute->output);
    }

    // ── The catalog ─────────────────────────────────────────────────────────────────────────────────

    public function test_the_two_hook_lifecycle_events_register_in_the_catalog(): void
    {
        $registry = $this->app->make(EventTypeRegistry::class);

        foreach ((new HookEventRegistrar)->types() as $type) {
            $this->assertNotNull($registry->find($type->name), "[{$type->name}] is missing from the catalog.");
            $this->assertSame(Hook::class, $registry->find($type->name)->subject);
        }

        $this->assertSame(['hooks'], array_values(array_unique(array_map(
            fn ($type) => $type->resourceKey(),
            (new HookEventRegistrar)->types(),
        ))));
    }

    // ── The signature ───────────────────────────────────────────────────────────────────────────────

    public function test_the_signature_is_t_and_v1_over_the_timestamped_body(): void
    {
        $header = HookSignature::sign('{"a":1}', 'sekrit', 1756180800);

        $this->assertSame(
            't=1756180800,v1='.hash_hmac('sha256', '1756180800.{"a":1}', 'sekrit'),
            $header,
        );
        $this->assertTrue(HookSignature::verify($header, '{"a":1}', 'sekrit', now: 1756180800));
    }

    public function test_a_tampered_body_fails_verification(): void
    {
        $header = HookSignature::sign('{"a":1}', 'sekrit', 1756180800);

        $this->assertFalse(HookSignature::verify($header, '{"a":2}', 'sekrit', now: 1756180800));
    }

    public function test_the_wrong_secret_fails_verification(): void
    {
        $header = HookSignature::sign('{"a":1}', 'sekrit', 1756180800);

        $this->assertFalse(HookSignature::verify($header, '{"a":1}', 'other', now: 1756180800));
    }

    public function test_a_replayed_delivery_falls_outside_the_tolerance_window(): void
    {
        $header = HookSignature::sign('{"a":1}', 'sekrit', 1756180800);

        // Inside the window it verifies; an hour later the SAME capture does not, and the attacker
        // cannot re-date it because `t` is inside the signed input.
        $this->assertTrue(HookSignature::verify($header, '{"a":1}', 'sekrit', now: 1756180800 + 60));
        $this->assertFalse(HookSignature::verify($header, '{"a":1}', 'sekrit', now: 1756180800 + 3600));
    }

    public function test_re_dating_a_captured_signature_invalidates_it(): void
    {
        $captured = HookSignature::sign('{"a":1}', 'sekrit', 1756180800);
        [, $digest] = HookSignature::parse($captured);

        $forged = sprintf('t=%d,v1=%s', 1756180800 + 3600, $digest);

        $this->assertFalse(HookSignature::verify($forged, '{"a":1}', 'sekrit', now: 1756180800 + 3600));
    }

    public function test_an_unknown_signature_version_is_ignored_rather_than_rejected(): void
    {
        $header = HookSignature::sign('{"a":1}', 'sekrit', 1756180800).',v2=whatever';

        $this->assertTrue(HookSignature::verify($header, '{"a":1}', 'sekrit', now: 1756180800));
    }

    public function test_a_malformed_header_is_not_verification(): void
    {
        foreach (['', 'garbage', 't=abc,v1=x', 'v1=x', 't=1756180800'] as $header) {
            $this->assertFalse(HookSignature::verify($header, '{"a":1}', 'sekrit', now: 1756180800));
        }
    }

    public function test_the_header_prefix_is_beam_and_is_host_overridable(): void
    {
        $this->assertSame('X-Beam-Signature', HookSignature::header('signature'));
        $this->assertSame('X-Beam-Delivery', HookSignature::header('delivery'));

        config()->set('webhooks.outbound.header_prefix', 'X-Acme-');
        $this->assertSame('X-Acme-Signature', HookSignature::header('signature'));
    }

    // ── The envelope + the wire ─────────────────────────────────────────────────────────────────────

    public function test_the_envelope_nests_the_payload_under_data(): void
    {
        $envelope = HookDeliveryEnvelope::forDelivery(new WebhookDelivery(
            event: 'tenants.provisioned',
            // A payload carrying its own `event` key — the exact collision the old flat merge lost.
            payload: ['event' => 'not-this-one', 'tenantId' => 't-1'],
            endpoint: 'https://receiver.test/hooks',
            idempotencyKey: 'delivery-1',
            hookId: 'hook-1',
            occurredAt: '2026-08-26T04:00:00+00:00',
        ));

        $this->assertSame([
            'id' => 'delivery-1',
            'event' => 'tenants.provisioned',
            'occurredAt' => '2026-08-26T04:00:00+00:00',
            'data' => ['event' => 'not-this-one', 'tenantId' => 't-1'],
            'hook' => ['id' => 'hook-1'],
        ], $envelope->toArray());
    }

    public function test_the_delivery_uuid_is_minted_once_and_survives_a_retry(): void
    {
        $delivery = new WebhookDelivery(
            event: 'tenants.provisioned',
            payload: [],
            endpoint: 'https://receiver.test/hooks',
        );

        $job = new DispatchWebhookJob($delivery);
        $first = $delivery->deliveryId();

        // Serialization round trip stands in for the queue putting the job down and picking it up.
        $revived = unserialize(serialize($job));

        $this->assertSame($first, $revived->delivery->deliveryId());
        $this->assertNotSame('', $first);
    }

    public function test_the_wire_carries_the_namespaced_headers_and_the_idempotency_key(): void
    {
        Http::fake();

        $hook = $this->hook();

        (new DispatchWebhookJob(new WebhookDelivery(
            event: 'tenants.provisioned',
            payload: ['tenantId' => 't-1'],
            endpoint: $hook->endpoint,
            secret: $hook->secret,
            hookId: (string) $hook->id,
        )))->handle();

        Http::assertSent(function ($request) use ($hook) {
            $this->assertSame('tenants.provisioned', $request->header('X-Beam-Event')[0]);
            $this->assertSame((string) $hook->id, $request->header('X-Beam-Hook')[0]);
            $this->assertSame($request->header('X-Beam-Delivery')[0], $request->header('Idempotency-Key')[0]);
            $this->assertNotEmpty($request->header('X-Beam-Signature')[0]);
            // The paid vendor name must not appear on a free-tier package's outbound headers (12 §5).
            foreach (array_keys($request->headers()) as $name) {
                $this->assertStringNotContainsStringIgnoringCase('splicewire', $name);
            }

            return true;
        });
    }

    public function test_the_signature_covers_the_exact_bytes_sent(): void
    {
        Http::fake();

        $hook = $this->hook();

        (new DispatchWebhookJob(new WebhookDelivery(
            event: 'tenants.provisioned',
            payload: ['tenantId' => 't-1', 'slug' => 'a/b'],
            endpoint: $hook->endpoint,
            secret: $hook->secret,
            hookId: (string) $hook->id,
        )))->handle();

        Http::assertSent(function ($request) use ($hook) {
            // Read the body off the REQUEST, not off a re-encoded envelope — see the class docblock.
            $this->assertTrue(
                HookSignature::verify($request->header('X-Beam-Signature')[0], $request->body(), $hook->secret),
                'The signature must verify against the literal bytes on the wire.',
            );

            return true;
        });
    }

    public function test_an_unsigned_delivery_is_still_legal_for_the_pre_hook_callback_case(): void
    {
        Http::fake();

        (new DispatchWebhookJob(new WebhookDelivery(
            event: 'tenants.provisioned',
            payload: [],
            endpoint: 'https://receiver.test/hooks',
            token: 'bearer-token',
        )))->handle();

        Http::assertSent(function ($request) {
            $this->assertSame([], $request->header('X-Beam-Signature'));
            $this->assertSame('Bearer bearer-token', $request->header('Authorization')[0]);

            return true;
        });
    }

    // ── Subject deletion ────────────────────────────────────────────────────────────────────────────

    public function test_deleting_the_subject_deletes_the_hook_rather_than_nulling_the_morph(): void
    {
        Bus::fake();

        Schema::create('hook_subjects', function (Blueprint $table) {
            $table->increments('id');
        });

        $subject = HookSubjectFixture::create([]);

        $narrowed = $this->hook(['subject_type' => $subject->getMorphClass(), 'subject_id' => (string) $subject->getKey()]);
        $bare = $this->hook();

        $subject->delete();

        $this->assertNull(Hook::find($narrowed->id), 'A narrowed subscription must not survive its subject.');
        $this->assertNotNull(Hook::find($bare->id), 'An unnarrowed subscription is untouched.');
    }
}
