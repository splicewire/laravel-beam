<?php

namespace Splicewire\Beam\Tests\Realm;

use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Schemastud\Frame\Contracts\ResourceAccessGate;
use Schemastud\Frame\Registry\NavMetadata;
use Schemastud\Frame\Registry\ResourceDefinition;
use Splicewire\Beam\Models\BeamSchema;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Realm\RealmEntitlementResourceGate;
use Splicewire\Beam\Tests\Entitlements\FakeEntitlementResolver;
use Splicewire\Beam\Tests\TestCase;

/**
 * Realm membership is an AUTHORIZATION, not a projection — beam's answer to frame's
 * {@see ResourceAccessGate} port.
 *
 * The measurement this exists for (2026-09-12, `https://fresh-tower.test`, signed in as
 * `demo-member`, `os.operate` = false, already 403 at `/operator`):
 * `GET /frame/resources/users`, `/frame/resources/teams` and `/frame/resources/tenants` each
 * answered **200** — every user's email, every team, and the tenant roster with its owner email.
 * The host's `config('frame.realms')` put `users`/`teams` in the operator realm and
 * `beam.core.realm_gates` hard-gated that realm; both were read by the nav projection and by the
 * realm manifest projector, and neither was ever asked at the socket.
 *
 * Each test below names the arm it holds. The two PERMIT arms are as load-bearing as the refusals:
 * an unrealmed resource keeps the row-level-scope posture `G1-BEAM-SCOPE-ISOLATION` proves, and a
 * resource that also lives in an ungated realm stays reachable through it.
 */
class RealmEntitlementResourceGateTest extends TestCase
{
    /**
     * `entitlement:{key}` abilities are defined at BOOT from the known key universe, so the key has
     * to exist before the provider runs — setting it in a test body would leave the ability
     * undefined and every assertion below would pass for the wrong reason (Gate denies an unknown
     * ability).
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('app.entitlements', ['os.operate' => [], 'studio.enter' => []]);
        $app['config']->set('beam.core.realm_gates', [
            'operator' => ['entitlement' => 'os.operate', 'mode' => 'hard'],
        ]);
    }

    private function holding(array $keys): void
    {
        $this->app->instance(EntitlementResolver::class, new FakeEntitlementResolver($keys));
    }

    /** Register `$key` into the membership authority with the realms a host would have declared. */
    private function register(string $key, array $realms): ResourceDefinition
    {
        $this->app->make(ParticleResourceRegistry::class)->register(
            new ParticleResource(key: $key, backing: BeamSchema::class, data: ResourceDefinition::class),
            $realms,
        );

        return new ResourceDefinition(
            key: $key,
            model: BeamSchema::class,
            data: ResourceDefinition::class,
            creatable: false,
            query: null,
            editData: null,
            policy: null,
            form: 'bare',
            nav: new NavMetadata(label: ucfirst($key)),
        );
    }

    private function gate(): RealmEntitlementResourceGate
    {
        return $this->app->make(RealmEntitlementResourceGate::class);
    }

    // ---- the defect ---------------------------------------------------------------------------

    public function test_an_operator_realm_resource_is_refused_to_a_principal_without_the_entitlement(): void
    {
        $definition = $this->register('users', ['operator']);
        $this->holding([]);

        $this->assertFalse($this->gate()->allowsResource($definition));
    }

    public function test_an_operator_realm_resource_is_served_to_an_entitled_principal(): void
    {
        $definition = $this->register('users', ['operator']);
        $this->holding(['os.operate']);

        $this->assertTrue($this->gate()->allowsResource($definition));
    }

    /**
     * The CENTRAL-realm fallback. A host that lists resources in its operator realm and forgets the
     * `realm_gates` entry must not get the measured defect back — "Empty gates NOTHING" would
     * otherwise be the whole posture of the operator console.
     */
    public function test_a_central_realm_with_no_declared_gate_still_falls_back_to_the_staff_entitlement(): void
    {
        config(['beam.core.realm_gates' => []]);

        $definition = $this->register('users', ['operator']);

        $this->holding([]);
        $this->assertFalse($this->gate()->allowsResource($definition));

        $this->holding(['os.operate']);
        $this->assertTrue($this->gate()->allowsResource($definition));
    }

    /** A host that spells its operator key differently declares it, and the declaration wins. */
    public function test_a_declared_gate_wins_over_the_central_fallback(): void
    {
        config(['beam.core.realm_gates' => [
            'operator' => ['entitlement' => 'studio.enter', 'mode' => 'hard'],
        ]]);

        $definition = $this->register('users', ['operator']);

        $this->holding(['os.operate']);
        $this->assertFalse($this->gate()->allowsResource($definition));

        $this->holding(['studio.enter']);
        $this->assertTrue($this->gate()->allowsResource($definition));
    }

    // ---- the permit arms ----------------------------------------------------------------------

    /**
     * The TENANT realm declares no gate, so its resources answer exactly as before — this is the
     * arm that keeps `G1-BEAM-SCOPE-ISOLATION` passing, and it must not be collapsed into "any
     * realm membership means staff".
     */
    public function test_a_tenant_realm_resource_is_untouched(): void
    {
        $definition = $this->register('beam-ux-entry', ['tenant']);
        $this->holding([]);

        $this->assertTrue($this->gate()->allowsResource($definition));
    }

    /**
     * An UNREALMED resource keeps today's posture. Every starter deliberately leaves `members`,
     * `invitations` and `tokens` out of `frame.realms` while the socket still serves them scoped per
     * team; refusing here would break that on a question about realms.
     */
    public function test_an_unrealmed_resource_is_untouched(): void
    {
        $definition = $this->register('members', []);
        $this->holding([]);

        $this->assertTrue($this->gate()->allowsResource($definition));
    }

    /**
     * Membership is a UNION. A resource in both realms is reachable through the ungated one —
     * operator membership must not SUBTRACT access from a tenant user.
     */
    public function test_a_resource_in_both_a_gated_and_an_ungated_realm_stays_reachable(): void
    {
        $definition = $this->register('sitemap', ['operator', 'tenant']);
        $this->holding([]);

        $this->assertTrue($this->gate()->allowsResource($definition));
    }

    /** Beam binds itself over frame's permit-everything default, or none of the above runs at a host. */
    public function test_beam_binds_this_gate_over_frames_open_default(): void
    {
        $this->assertInstanceOf(
            RealmEntitlementResourceGate::class,
            $this->app->make(ResourceAccessGate::class)
        );
    }
}
