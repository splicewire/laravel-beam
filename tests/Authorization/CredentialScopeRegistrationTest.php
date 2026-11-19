<?php

namespace Splicewire\Beam\Tests\Authorization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rushing\PermissionCascade\Authorization\CredentialScopeGate;
use Rushing\PermissionCascade\Authorization\CredentialScopeRefusal;
use Rushing\PermissionCascade\Contracts\CredentialScopeResolver;
use Splicewire\Beam\Authorization\AccessError;
use Splicewire\Beam\Tests\TestCase;

/**
 * Beam keeps only the registration of the permission cascade's credential-scope gate (credential-scope 02; fleet ADR
 * 0006). The gate's behaviour is the cascade's and is tested there (`CredentialScopeGateTest`). Pinned here: beam
 * attaches it as the FIRST `Gate::before`, so a host's allow-all before added later cannot answer a scoped decision
 * first; its refusal is coded with beam's {@see AccessError}; and the old beam class name still resolves to it.
 */
class CredentialScopeRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scoped_widgets', function (Blueprint $table): void {
            $table->id();
        });
        ScopedWidget::create();

        Gate::define('widgets.export', fn (?User $user = null) => $user !== null);
        Gate::define('rebootFleet', fn (?User $user = null) => $user !== null);
        // A host's allow-all before, added after beam's: it must not answer a scoped decision first.
        Gate::before(fn ($user = null) => $user instanceof ScopedPrivilegedUser ? true : null);

        Route::get('/scoped/flat', fn () => tap(['ok' => true], fn () => Gate::authorize('widgets.export')));
        Route::get('/scoped/reboot', fn () => tap(['ok' => true], fn () => Gate::authorize('rebootFleet')));
        Route::get('/scoped/widgets/{id}/{verb}', fn (string $id, string $verb) => tap(['ok' => true],
            fn () => Gate::authorize($verb, ScopedWidget::query()->findOrFail($id))));
    }

    public function test_beam_registers_the_cascade_gate_ahead_of_a_hosts_allow_all_before(): void
    {
        $this->actingAs(new ScopedPrivilegedUser);

        $this->scopeTo(['widgets.approve']);
        $this->getJson('/scoped/flat')->assertForbidden()->assertJson(['code' => AccessError::CredentialScopeNameNotCarried->value]);
        $this->getJson('/scoped/widgets/1/view')->assertForbidden();

        $this->scopeTo(['widgets.export', 'scoped-widget.view']);
        $this->getJson('/scoped/flat')->assertOk();
        $this->getJson('/scoped/widgets/1/view')->assertOk();

        $this->scopeTo(null);
        $this->getJson('/scoped/reboot')->assertOk();
    }

    public function test_a_refusal_is_coded_with_beams_access_error(): void
    {
        $this->actingAs(new ScopedPrivilegedUser);
        $this->scopeTo(['rebootFleet']);

        $this->getJson('/scoped/reboot')->assertForbidden()->assertExactJson([
            'message' => AccessError::CredentialScopeUnnamedDecision->message(['key' => 'rebootFleet']),
            'code' => CredentialScopeRefusal::UNNAMED,
        ]);
        $this->assertSame(AccessError::CredentialScopeUnnamedDecision->value, CredentialScopeRefusal::UNNAMED);
        $this->assertSame(AccessError::CredentialScopeNameNotCarried->value, CredentialScopeRefusal::NOT_CARRIED);
    }

    public function test_the_old_beam_class_name_resolves_to_the_cascade_gate(): void
    {
        $this->assertInstanceOf(CredentialScopeGate::class, app(\Splicewire\Beam\Authorization\CredentialScopeGate::class));
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

class ScopedPrivilegedUser extends User
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
