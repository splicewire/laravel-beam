<?php

namespace Splicewire\Beam\Tests\Realm;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Splicewire\Beam\Realm\RealmManifestProjector;
use Splicewire\Beam\Realm\RealmOverlay;
use Splicewire\Beam\Realm\RealmOverlayRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * L3 ticket 10a (orch-lead 13:20Z): each realm's gate is evaluated against the principal THAT REALM belongs to.
 *
 * Both hosts read realms inside the tenant boundary, where the request's principal is the user's tenant SEAT. The
 * operator realm is central (`RealmDefinition::$central`), and operator reach is granted to the CENTRAL user, so asking
 * the seat omitted Operator for a fresh Tower's owner (tots a3f3f3e6). The central realm is now asked of the central
 * user behind the seat; tenant, site and user realms stay on the seat, so tenant admission does not widen.
 */
class RealmGatePrincipalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gate_principal_central_users', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('global_id');
            $table->string('email');
        });
        GatePrincipalCentralUser::query()->insert([
            ['id' => 'owner', 'global_id' => 'owner', 'email' => 'owner@example.test'],
            ['id' => 'member', 'global_id' => 'member', 'email' => 'member@example.test'],
            // A global key that is NOT the primary key: the owner is `c-77` by key and `g-1` globally, while the row
            // whose PRIMARY key is `g-1` is somebody else (a member).
            ['id' => 'c-77', 'global_id' => 'g-1', 'email' => 'owner@example.test'],
            ['id' => 'g-1', 'global_id' => 'g-other', 'email' => 'member@example.test'],
        ]);

        // The installer-default owner's operator reach is CENTRAL; `tenant-pro` is held by seats only.
        $this->app->instance(EntitlementResolver::class, new class implements EntitlementResolver
        {
            public function entitlementsFor(mixed $principal): array
            {
                return match (true) {
                    $principal instanceof GatePrincipalCentralUser && $principal->email === 'owner@example.test' => ['os.operate'],
                    $principal instanceof GatePrincipalSeat && $principal->getKey() === 'seat-grants-operate' => ['tenant-pro', 'os.operate'],
                    $principal instanceof GatePrincipalSeat => ['tenant-pro'],
                    // null is "the request's scope" (a host resolves it per request): here, the seat's tenant.
                    $principal === null => ['tenant-pro'],
                    default => [],
                };
            }
        });

        config(['beam.core.realm_gates' => [
            'operator' => ['entitlement' => 'os.operate', 'mode' => 'hard'],
            'tenant' => ['entitlement' => 'tenant-pro', 'mode' => 'soft', 'upsell' => ['title' => 'Pro']],
        ]]);
    }

    /** @return array<string, array<string, mixed>> */
    private function project(mixed $principal): array
    {
        return collect($this->app->make(RealmManifestProjector::class)->project($principal))->keyBy('key')->all();
    }

    private function seat(string $id): GatePrincipalSeat
    {
        return (new GatePrincipalSeat)->forceFill(['id' => $id]);
    }

    public function test_the_central_user_is_found_by_the_seats_own_global_key_name_not_the_central_primary_key(): void
    {
        // As stancl's Syncable listener queries: getCentralModelName()::where(seat key NAME, seat key VALUE).
        $seat = (new GatePrincipalSeat)->forceFill(['id' => 'seat-x', 'global_id' => 'g-1']);
        $seat->globalKeyName = 'global_id';

        $this->assertArrayHasKey('operator', $this->project($seat));
    }

    public function test_a_principal_without_the_full_syncable_shape_is_asked_itself(): void
    {
        $partial = (new GatePrincipalPartialSeat)->forceFill(['id' => 'owner']);

        $this->assertArrayNotHasKey('operator', $this->project($partial));
    }

    public function test_a_central_realms_overlay_capability_stays_locked_for_a_seat_with_no_central_user(): void
    {
        // The operator realm UNGATED, so it projects; its overlay capability needs `tenant-pro`, which the SEAT holds.
        // The seat has no central row, so the realm has no principal, and no capability may unlock from the seat.
        config(['beam.core.realm_gates' => []]);
        $this->app->make(RealmOverlayRegistry::class)->register(RealmOverlay::make(
            realmKey: 'operator',
            overlay: ['overlay' => '1.0.0', 'actions' => []],
            capabilities: [['key' => 'ops.pro', 'entitlement' => 'tenant-pro', 'upsell' => ['cta' => 'Upgrade']]],
        ));

        $capability = collect($this->project($this->seat('nobody'))['operator']['capabilities'])->firstWhere('key', 'ops.pro');

        $this->assertTrue($capability['locked']);
    }

    public function test_the_owners_seat_sees_the_central_operator_realm_through_its_central_user(): void
    {
        $realms = $this->project($this->seat('owner'));

        $this->assertArrayHasKey('operator', $realms);
        $this->assertFalse($realms['operator']['locked']);
    }

    public function test_a_seated_member_whose_central_user_lacks_os_operate_never_sees_operator(): void
    {
        $this->assertArrayNotHasKey('operator', $this->project($this->seat('member')));
    }

    public function test_a_seats_own_grants_do_not_open_the_central_realm(): void
    {
        // The seat's resolver answer includes os.operate, but the realm belongs to the central user, who holds none
        // (no central row at all for this id), so the lookup finds nobody and the central realm stays closed.
        $this->assertArrayNotHasKey('operator', $this->project($this->seat('seat-grants-operate')));
    }

    public function test_tenant_realms_are_still_judged_on_the_seat_for_both(): void
    {
        foreach (['owner', 'member'] as $id) {
            $tenant = $this->project($this->seat($id))['tenant'];
            // `tenant-pro` is held by the SEAT only; the central user does not hold it. Unlocked means the seat was
            // asked; asking the central user would have locked it.
            $this->assertFalse($tenant['locked'], $id);
        }
    }

    public function test_tenant_admission_does_not_widen_to_the_central_users_grants(): void
    {
        config(['beam.core.realm_gates.tenant' => ['entitlement' => 'os.operate', 'mode' => 'soft', 'upsell' => ['title' => 'x']]]);

        // The owner's CENTRAL user holds os.operate, the owner's seat does not: a tenant realm gated on it stays locked.
        $this->assertTrue($this->project($this->seat('owner'))['tenant']['locked']);
    }

    public function test_a_central_principal_is_asked_directly_unchanged(): void
    {
        $owner = GatePrincipalCentralUser::query()->findOrFail('owner');
        $member = GatePrincipalCentralUser::query()->findOrFail('member');

        $this->assertArrayHasKey('operator', $this->project($owner));
        $this->assertArrayNotHasKey('operator', $this->project($member));
    }

    public function test_a_principal_with_no_central_model_is_asked_itself(): void
    {
        // A plain user (no Syncable shape): asked directly, as before this change.
        $this->assertArrayNotHasKey('operator', $this->project((new User)->forceFill(['id' => 'owner'])));
        $this->assertArrayNotHasKey('operator', $this->project(null));
    }
}

/** The central user model: the side a tenant seat syncs from. */
class GatePrincipalCentralUser extends User
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'gate_principal_central_users';

    protected $guarded = [];
}

/**
 * A tenant seat in stancl's `Syncable` shape (as `Splicewire\Beam\Tenancy\Models\TenantUser` is): it names its central
 * model and shares the central user's global id. Never persisted.
 */
class GatePrincipalSeat extends User
{
    public string $globalKeyName = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function getCentralModelName(): string
    {
        return GatePrincipalCentralUser::class;
    }

    public function getGlobalIdentifierKey(): mixed
    {
        return $this->getAttribute($this->getGlobalIdentifierKeyName());
    }

    public function getGlobalIdentifierKeyName(): string
    {
        return $this->globalKeyName;
    }
}

/** Two of the three Syncable lookup methods only: not a seat, so it is asked as itself. */
class GatePrincipalPartialSeat extends User
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function getCentralModelName(): string
    {
        return GatePrincipalCentralUser::class;
    }

    public function getGlobalIdentifierKey(): mixed
    {
        return $this->getKey();
    }
}
