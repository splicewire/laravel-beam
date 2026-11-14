<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Localization\Client\LangFingerprint;
use Splicewire\Beam\Realm\RealmGateAbility;
use Splicewire\Beam\Realm\RealmRegistry;
use Splicewire\Beam\Tests\TestCase;

uses(TestCase::class);

/*
 * localization 16 amend (B11-B1; SIGNATURES-16A, cleared 03:12Z): a realm's client catalog is read through the realm's
 * OWN door, RealmGateAbility via the Gate, asked of the user the host's middleware resolved, before resolve, ETag
 * and 304. There's no new ability or grant, and no guard is guessed.
 */

beforeEach(function (): void {
    $this->lang = sys_get_temp_dir().'/beam-client-authz-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($this->lang.'/vendor/schemastud-ui/es/operator', 0777, true);
    file_put_contents($this->lang.'/vendor/schemastud-ui/es/messages.php', "<?php return ['save' => 'Guardar'];");
    file_put_contents($this->lang.'/vendor/schemastud-ui/es/operator/messages.php', "<?php return ['save' => 'Guardar (operador)'];");
    $this->app->useLangPath($this->lang);
    $this->app->forgetInstance('translation.loader');
    $this->app->forgetInstance('translator');
    config([
        'beam.localization.client_catalog' => dirname(__DIR__).'/Fixtures/Localization/discovered-catalog.json',
        'beam.localization.supported' => ['en', 'es'],
        'auth.guards.staff' => ['driver' => 'session', 'provider' => 'users'],
        'app.key' => 'base64:'.base64_encode(random_bytes(32)),
    ]);
    $this->app->forgetScopedInstances();
    @unlink(LangFingerprint::artifactPath());
    Gate::define('entitlement:os.operate', fn (?GenericUser $user): bool => $user?->getAuthIdentifier() === 'operator');
});

function catalogUser(string $id): GenericUser
{
    return new GenericUser(['id' => $id, 'name' => $id]);
}

it('gates operator on the realm rule the console uses: entitlement:os.operate', function (): void {
    expect(RealmGateAbility::for('operator', app(RealmRegistry::class)))->toBe('entitlement:os.operate');
});

it('refuses a guest a gated realm with a coded 403, and never a 304 for the right ETag', function (): void {
    $this->actingAs(catalogUser('operator'));
    $etag = $this->getJson('/beam/localization/catalog/es/operator')->assertOk()->headers->get('ETag');
    auth()->forgetGuards();

    $this->getJson('/beam/localization/catalog/es/operator')->assertForbidden()->assertJsonPath('code', 'client_catalog_realm_forbidden');
    $this->getJson('/beam/localization/catalog/es/operator', ['If-None-Match' => $etag])->assertForbidden();
});

it('refuses a wrong-realm principal and serves an entitled one, then 304s its ETag', function (): void {
    $this->actingAs(catalogUser('member'));
    $this->getJson('/beam/localization/catalog/es/operator')->assertForbidden()->assertJsonPath('code', 'client_catalog_realm_forbidden');

    $this->actingAs(catalogUser('operator'));
    $response = $this->getJson('/beam/localization/catalog/es/operator')->assertOk()->assertJsonPath('realm', 'operator');
    expect($response->json('messages')['schemastud-ui::messages.save']['text'])->toBe('Guardar (operador)');
    $this->getJson('/beam/localization/catalog/es/operator', ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
});

it('fails closed for a user only another guard knows: no guard is guessed past the host middleware', function (): void {
    // The staff guard holds an entitled user, but the host's middleware (web) authenticates nobody, so the request has
    // no user and the realm's gate refuses.
    auth()->guard('staff')->setUser(catalogUser('operator'));
    expect(auth()->guard('staff')->check())->toBeTrue();

    $this->getJson('/beam/localization/catalog/es/operator')->assertForbidden();
});

it('keeps the realm-less base catalog where the host middleware admits it, and 404s an unknown realm as before', function (): void {
    $this->getJson('/beam/localization/catalog/es')->assertOk()->assertJsonPath('realm', null);
    $this->getJson('/beam/localization/catalog/es/no-such-realm')->assertNotFound()->assertJsonPath('code', 'client_catalog_realm_unknown');
});

it('serves an ungated realm as its own door does', function (): void {
    $ungated = collect(array_keys(app(RealmRegistry::class)->all()))
        ->first(fn (string $realm): bool => RealmGateAbility::for($realm, app(RealmRegistry::class)) === null);
    if ($ungated === null) {
        $this->markTestSkipped('No ungated realm is registered in the test app.');
    }

    $this->getJson('/beam/localization/catalog/es/'.$ungated)->assertOk()->assertJsonPath('realm', $ungated);
});

it('runs the host middleware (the session and tenancy seam) before the controller', function (): void {
    $seen = [];
    Route::aliasMiddleware('catalog-tenancy', function ($request, Closure $next) use (&$seen) {
        $seen[] = 'tenancy';

        return $next($request);
    });
    config(['beam.localization.client_catalog_middleware' => ['web', 'catalog-tenancy']]);
    Route::get('beam/localization/catalog/{locale}/{realm?}', Splicewire\Beam\Localization\Client\Http\ClientCatalogController::class)
        ->middleware((array) config('beam.localization.client_catalog_middleware'))
        ->name('beam.localization.catalog');

    $this->getJson('/beam/localization/catalog/es')->assertOk();
    expect($seen)->toBe(['tenancy']);
});
