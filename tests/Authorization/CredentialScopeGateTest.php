<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Splicewire\Beam\Authorization\CredentialScopeGate;
use Splicewire\Beam\Tests\TestCase;

/**
 * standwell gap-assessment-build 39: a scoped credential narrows the checks OUTSIDE the permission cascade and the
 * particle op gate, through the `Gate::before` beam registers and the superuser seam a host's allow-all before uses.
 *
 * Each site is a plain route, so the 403 is the framework's own: a flat name asked by a controller, a `can:` route
 * middleware, a verb on a policy that does not extend `BaseModelPolicy` (it asks a flat permission), and a host's
 * superuser `Gate::before`. The planes left alone are pinned beside them: the entitlement plane, a session (unscoped)
 * call, a guest, a check made for someone other than the acting principal, and a decision that asks no permission.
 */
class CredentialScopeGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scoped_widgets', function (Blueprint $table): void {
            $table->id();
        });
        ScopedWidget::create();

        // The principal's permissions: a "privileged" user holds both flat names; a guest-allowed one for the guest case.
        Gate::define('widgets.export', fn (?User $user = null) => $user instanceof ScopedPrivilegedUser);
        Gate::define('widgets.approve', fn (?User $user = null) => $user instanceof ScopedPrivilegedUser);
        Gate::define('widgets.public', fn (?User $user = null) => true);
        Gate::define('entitlement:widgets.premium', fn (?User $user = null) => true);
        Gate::policy(ScopedWidget::class, ScopedWidgetPolicy::class);

        // A host superuser before (the flagship's Root), made scope-aware through the seam.
        Gate::before(fn ($user = null, $ability = null, $arguments = []) => $user instanceof ScopedRootUser
            ? app(CredentialScopeGate::class)->superuser($user, $ability, $arguments)
            : null);

        Route::get('/scoped/flat', function () {
            Gate::authorize('widgets.export');

            return ['ok' => true];
        });
        Route::get('/scoped/can', fn () => ['ok' => true])->middleware('can:widgets.export');
        Route::get('/scoped/widgets/{id}/{verb}', function (string $id, string $verb) {
            Gate::authorize($verb, ScopedWidget::query()->findOrFail($id));

            return ['ok' => true];
        });
        Route::get('/scoped/entitlement', function () {
            Gate::authorize('entitlement:widgets.premium');

            return ['ok' => true];
        });
        Route::get('/scoped/public', function () {
            Gate::authorize('widgets.public');

            return ['ok' => true];
        });
        Route::get('/scoped/other-user', fn () => ['other' => Gate::forUser(new ScopedPrivilegedUser)->allows('widgets.export')]);
    }

    public function test_a_flat_name_asked_by_a_controller_or_a_can_middleware_is_narrowed_by_the_scope(): void
    {
        $this->actingAs(new ScopedPrivilegedUser);

        $this->scopeTo(['widgets.approve']);
        $this->getJson('/scoped/flat')->assertForbidden();
        $this->getJson('/scoped/can')->assertForbidden();

        $this->scopeTo(['widgets.export']);
        $this->getJson('/scoped/flat')->assertOk();
        $this->getJson('/scoped/can')->assertOk();

        // A session or a `*` token: unscoped, unchanged.
        $this->scopeTo(null);
        $this->getJson('/scoped/flat')->assertOk();
        $this->getJson('/scoped/can')->assertOk();
    }

    public function test_a_verb_on_a_policy_outside_the_cascade_is_narrowed_through_the_permission_it_asks(): void
    {
        $this->actingAs(new ScopedPrivilegedUser);

        $this->scopeTo(['widgets.export']);
        $this->getJson('/scoped/widgets/1/approve')->assertForbidden();

        $this->scopeTo(['widgets.approve']);
        $this->getJson('/scoped/widgets/1/approve')->assertOk();

        $this->scopeTo(null);
        $this->getJson('/scoped/widgets/1/approve')->assertOk();
    }

    public function test_a_superuser_before_does_not_carry_a_scoped_credential_past_a_check(): void
    {
        $this->actingAs(new ScopedRootUser);

        // Scoped to one name: the superuser keeps exactly that name and nothing else.
        $this->scopeTo(['widgets.export']);
        $this->getJson('/scoped/flat')->assertOk();
        $this->getJson('/scoped/can')->assertOk();
        // A verb on a non-cascade policy: its definition asks `widgets.approve`, which the scope lacks.
        $this->getJson('/scoped/widgets/1/approve')->assertForbidden();
        // A custom verb no policy method or ability defines: only the superuser before ever answered it.
        $this->getJson('/scoped/widgets/1/reset')->assertForbidden();

        // The definition's permission, and the custom verb's derived name (the cascade's naming), admit.
        $this->scopeTo(['widgets.approve', 'scoped-widget.reset']);
        $this->getJson('/scoped/widgets/1/approve')->assertOk();
        $this->getJson('/scoped/widgets/1/reset')->assertOk();
        $this->getJson('/scoped/flat')->assertForbidden();

        // The per-instance derived name admits too.
        $this->scopeTo(['scoped-widget.1.reset']);
        $this->getJson('/scoped/widgets/1/reset')->assertOk();

        // Unscoped (a session, a `*` token): the superuser passes everything, as before.
        $this->scopeTo(null);
        $this->getJson('/scoped/flat')->assertOk();
        $this->getJson('/scoped/widgets/1/approve')->assertOk();
        $this->getJson('/scoped/widgets/1/reset')->assertOk();
    }

    public function test_the_entitlement_plane_is_not_narrowed(): void
    {
        $this->scopeTo([]);

        $this->actingAs(new ScopedPrivilegedUser);
        $this->getJson('/scoped/entitlement')->assertOk();

        $this->actingAs(new ScopedRootUser);
        $this->getJson('/scoped/entitlement')->assertOk();
    }

    public function test_a_guest_and_a_check_for_someone_other_than_the_acting_principal_are_not_narrowed(): void
    {
        $this->scopeTo([]);

        // A guest holds no credential to narrow.
        $this->getJson('/scoped/public')->assertOk();

        // The scope belongs to the credential that authenticated THIS request, not to every user a check names.
        $this->actingAs(new ScopedPrivilegedUser);
        $this->getJson('/scoped/other-user')->assertOk()->assertJson(['other' => true]);
        $this->getJson('/scoped/flat')->assertForbidden();
    }

    public function test_a_decision_that_asks_no_permission_is_left_to_its_policy(): void
    {
        // Membership or ownership: there is no permission name in a token's vocabulary to narrow it by (inventory).
        $this->actingAs(new ScopedPrivilegedUser);
        $this->scopeTo([]);

        $this->getJson('/scoped/widgets/1/view')->assertOk();
    }

    /** @param  list<string>|null  $scope */
    private function scopeTo(?array $scope): void
    {
        $this->app->instance(CredentialScopeResolver::class, new ScopedFixedScope($scope));
    }
}

class ScopedWidget extends Model
{
    protected $table = 'scoped_widgets';

    public $timestamps = false;

    protected $guarded = [];

    public function getMorphClass()
    {
        return 'scoped-widget';
    }
}

/** A policy outside the cascade: `approve` asks a flat permission, `view` asks none. No `reset` method. */
class ScopedWidgetPolicy
{
    public function approve(User $user, ScopedWidget $widget): bool
    {
        return $user->can('widgets.approve');
    }

    public function view(User $user, ScopedWidget $widget): bool
    {
        return true;
    }
}

class ScopedPrivilegedUser extends User
{
    protected $table = 'users';
}

/** A principal a host's superuser `Gate::before` answers for. */
class ScopedRootUser extends User
{
    protected $table = 'users';
}

class ScopedFixedScope implements CredentialScopeResolver
{
    /** @param  list<string>|null  $scope */
    public function __construct(private ?array $scope) {}

    public function resolve(): ?array
    {
        return $this->scope;
    }
}
