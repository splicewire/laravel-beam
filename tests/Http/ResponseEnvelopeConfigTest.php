<?php

namespace Splicewire\Beam\Tests\Http;

use Splicewire\Beam\Http\ArrayResponseEnvelope;
use Splicewire\Beam\Http\ConfiguredResponseEnvelope;
use Splicewire\Beam\Http\Contracts\ResponseEnvelope;
use Splicewire\Beam\Http\ResponseBodyEnvelope;
use Splicewire\Beam\Surgeon\ResponseEnvelopeAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * The response envelope is a DECLARED config key, not a container bind a host has to remember.
 *
 * ## Why this test exists
 *
 * Measured across `~/Herd/*` on 2026-09-01 (particle-manifest-repatriation ticket 04): exactly ONE host
 * bound {@see ResponseEnvelope}, and the consequence was that `~/Herd/splicewire-app` answered
 * `{ success, message, data }` while `~/Herd/tower` answered `{ data }` — same package, same routes,
 * divergent wire contracts, decided by which provider a host happened to run. Both classes were already
 * in beam-core; only the ADAPTER JOINING THEM was stranded above.
 *
 * A container bind is not auditable — nothing can report "which envelope does this host serve" without
 * booting the host and resolving the port. A config key is, which is what
 * {@see ResponseEnvelopeAudit} reads.
 *
 * ## The default stays NEUTRAL
 *
 * `ArrayResponseEnvelope` remains beam's shipped value on the stated ground its own docblock argues: a
 * headless beam host must get working particle responses with no host wiring. This test pins that the
 * key exists and is honoured — NOT that the rich shape becomes the default.
 */
class ResponseEnvelopeConfigTest extends TestCase
{
    public function test_beam_ships_the_neutral_envelope_as_the_configured_default(): void
    {
        $this->assertSame(
            ArrayResponseEnvelope::class,
            config('beam.core.http.envelope'),
            'beam-core must declare its neutral default in config, not only in a bind.'
        );

        $this->assertInstanceOf(ArrayResponseEnvelope::class, $this->app->make(ResponseEnvelope::class));
    }

    public function test_the_binding_reads_the_config_key(): void
    {
        config(['beam.core.http.envelope' => ResponseBodyEnvelope::class]);

        $this->assertInstanceOf(ResponseBodyEnvelope::class, $this->app->make(ResponseEnvelope::class));
    }

    /**
     * Read at RESOLVE time, never stamped at `register()`.
     *
     * This is the ordering fact tower depends on: tower's `packageRegistered()` runs AFTER beam's, so a
     * value written there has to still be seen. Stamping the class-string into the binding at register
     * time would make provider order decide the wire contract all over again — the exact defect this
     * ticket removes.
     */
    public function test_a_value_set_after_boot_still_wins(): void
    {
        $this->assertInstanceOf(ArrayResponseEnvelope::class, $this->app->make(ResponseEnvelope::class));

        config(['beam.core.http.envelope' => ResponseBodyEnvelope::class]);

        $this->assertInstanceOf(ResponseBodyEnvelope::class, $this->app->make(ResponseEnvelope::class));
    }

    /**
     * A host that genuinely wants a third shape binds the port directly, and that still wins — a config
     * default must never take an explicit bind away from a host.
     */
    /**
     * The binding and {@see ResponseEnvelopeAudit} read the key through ONE
     * class, so the audit can never report a shape the runtime does not serve.
     */
    public function test_the_config_key_has_exactly_one_reader(): void
    {
        $this->assertSame('beam.core.http.envelope', ConfiguredResponseEnvelope::KEY);
        $this->assertSame(ArrayResponseEnvelope::class, ConfiguredResponseEnvelope::DEFAULT);
        $this->assertSame(ArrayResponseEnvelope::class, ConfiguredResponseEnvelope::resolve());

        config(['beam.core.http.envelope' => 'not-a-class']);

        $this->assertFalse(ConfiguredResponseEnvelope::usable(config('beam.core.http.envelope')));
        $this->assertSame(ArrayResponseEnvelope::class, ConfiguredResponseEnvelope::resolve());
        $this->assertInstanceOf(ArrayResponseEnvelope::class, $this->app->make(ResponseEnvelope::class));
    }

    public function test_a_direct_host_bind_still_overrides_the_config(): void
    {
        $this->app->bind(ResponseEnvelope::class, ResponseBodyEnvelope::class);

        $this->assertInstanceOf(ResponseBodyEnvelope::class, $this->app->make(ResponseEnvelope::class));
    }

    /**
     * The key decides a WIRE SHAPE, not just a class, so one test asserts the shape a controller would
     * actually hand back — the level the flagship's contract is stated at.
     *
     * The two envelopes are the two published contracts: neutral `{ data: … }` and rich
     * `{ success, message, data }`. A test that only compared classes would pass against a refactor that
     * quietly changed what either one emits.
     */
    public function test_each_configured_envelope_emits_its_published_body(): void
    {
        $body = fn () => json_decode(
            $this->app->make(ResponseEnvelope::class)->item(['id' => 'x'])->toResponse(request())->getContent(),
            true,
        );

        $this->assertSame(['data' => ['id' => 'x']], $body());

        config(['beam.core.http.envelope' => ResponseBodyEnvelope::class]);

        $rich = $body();
        $this->assertArrayHasKey('success', $rich);
        $this->assertArrayHasKey('message', $rich);
        $this->assertSame(['id' => 'x'], $rich['data']);
    }

    /**
     * An unusable value must not take the whole HTTP surface down at a host that mistyped a class-string.
     * The doctor audit is where a bad value is REPORTED; the runtime falls back to the shipped default so
     * particle responses keep working.
     */
    public function test_an_unresolvable_class_string_falls_back_to_the_neutral_default(): void
    {
        config(['beam.core.http.envelope' => 'Splicewire\\Beam\\Http\\NoSuchEnvelope']);

        $this->assertInstanceOf(ArrayResponseEnvelope::class, $this->app->make(ResponseEnvelope::class));
    }
}
