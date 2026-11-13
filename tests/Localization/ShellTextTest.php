<?php

namespace Splicewire\Beam\Tests\Localization;

use Illuminate\Support\Facades\Route;
use Rushing\PermissionCascade\Contracts\EntitlementResolver;
use Splicewire\Beam\Brand\Brand;
use Splicewire\Beam\Ia\HostIa;
use Splicewire\Beam\Localization\Catalog\KeyCatalog;
use Splicewire\Beam\Localization\Locales;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Localization\ShellText;
use Splicewire\Beam\Nav\NavSection;
use Splicewire\Beam\Nav\NavSectionRegistry;
use Splicewire\Beam\Realm\RealmManifestProjector;
use Splicewire\Beam\Realm\RealmOverlay;
use Splicewire\Beam\Realm\RealmOverlayRegistry;
use Splicewire\Beam\Tests\Entitlements\FakeEntitlementResolver;
use Splicewire\Beam\Tests\TestCase;

/**
 * localization 11 — the shell's DECLARED text resolves at emit (ruling 01 §3; coord-lead rulings 21:25Z and 21:26Z):
 *
 * - brand copy: explicit `beam::brand.tagline`, `.passkey_copy` and `.title_template`, defaulting to the host's
 *   configured value (`name` and `legalEntity` are not keyed);
 * - a realm's label: `<ns>::realms.<realm>.label` in the registering package's namespace (kit realms: beam's), the
 *   configured label as the default, in both the host IA's realms and the launcher manifest;
 * - a nav section's label: keyed in the namespace of the header seat's registrant, carried through the merge.
 */
class ShellTextTest extends TestCase
{
    private string $lang;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'beam.core.realm_gates' => [],
            'beam.brand' => ['name' => 'Acme', 'tagline' => 'Ship it', 'title_template' => ':title · :name'],
        ]);
        $this->app->instance(EntitlementResolver::class, new FakeEntitlementResolver([]));
        Route::get('/', fn () => 'site')->name('home');
        Route::get('dashboard', fn () => 'app')->name('dashboard');
        Route::get('settings/profile', fn () => 'settings')->name('profile.edit');
        Route::get('operator', fn () => 'operator')->name('operator.home');
        app('router')->getRoutes()->refreshNameLookups();
    }

    /** @param  array<string, array<string, mixed>>  $files  path under the lang root => lines */
    private function lines(array $files): void
    {
        $this->lang = sys_get_temp_dir().'/beam-shell-'.getmypid().'-'.bin2hex(random_bytes(4));
        foreach ($files as $path => $content) {
            @mkdir(dirname("{$this->lang}/{$path}"), 0777, true);
            file_put_contents("{$this->lang}/{$path}", '<?php return '.var_export($content, true).';');
        }
        $this->app->useLangPath($this->lang);
        $this->app->forgetInstance('translation.loader');
        $this->app->forgetInstance('translator');
    }

    /** @return array<string, string> realm key => label, as the host IA projects it */
    private function realmLabels(): array
    {
        return collect($this->app->make(HostIa::class)->realms(null, '/')->toArray()['realms'])->pluck('label', 'key')->all();
    }

    /** @return array<string, string> realm key => launcher title */
    private function launcherTitles(): array
    {
        return collect($this->app->make(RealmManifestProjector::class)->project(null))->pluck('title', 'key')->all();
    }

    public function test_brand_copy_renders_the_configured_english_unchanged(): void
    {
        $this->lines([]);
        $brand = Brand::for();

        $this->assertSame('Acme', $brand->name);
        $this->assertSame('Ship it', $brand->tagline);
        $this->assertSame(':title · :name', $brand->titleTemplate);
        $this->assertSame("Passkeys secure your Acme login. They're tied to your account.", $brand->passkeyCopy);
    }

    public function test_brand_copy_reads_beams_explicit_keys_and_never_keys_the_name(): void
    {
        $this->lines(['vendor/beam/es/brand.php' => [
            'tagline' => 'Envíalo',
            'passkey_copy' => 'Las llaves de acceso protegen tu cuenta de :name.',
            'title_template' => ':title — :name',
            'name' => 'Nunca',
        ]]);
        $this->app->setLocale('es');
        $brand = Brand::for();

        $this->assertSame('Envíalo', $brand->tagline);
        $this->assertSame('Las llaves de acceso protegen tu cuenta de Acme.', $brand->passkeyCopy);
        // The renderer's placeholders survive untouched.
        $this->assertSame(':title — :name', $brand->titleTemplate);
        $this->assertSame('Acme', $brand->name);
    }

    public function test_an_unset_tagline_stays_unset(): void
    {
        config(['beam.brand' => ['name' => 'Acme']]);
        $this->lines(['vendor/beam/es/brand.php' => ['tagline' => 'Envíalo']]);
        $this->app->setLocale('es');

        $this->assertNull(Brand::for()->tagline);
    }

    public function test_a_realms_configured_label_renders_unchanged_in_english(): void
    {
        config(['beam.core.realms.tenant.label' => 'Workspace']);
        $this->lines([]);

        $this->assertSame('Workspace', $this->realmLabels()['tenant']);
        $this->assertSame('Operator', $this->realmLabels()['operator']);
    }

    public function test_a_kit_realms_label_reads_beams_key_in_the_ia_and_the_launcher(): void
    {
        $this->lines(['vendor/beam/es/realms.php' => ['tenant' => ['label' => 'Espacio'], 'operator' => ['label' => 'Operador']]]);
        $this->app->setLocale('es');

        $this->assertSame('Espacio', $this->realmLabels()['tenant']);
        $this->assertSame('Operador', $this->realmLabels()['operator']);
        $this->assertSame('Operador', $this->launcherTitles()['operator']);
    }

    public function test_the_shells_declared_text_is_marked_under_the_pseudo_locale(): void
    {
        $this->lines([]);
        config(['beam.localization.pseudo' => true]);
        $this->app->setLocale(Locales::PSEUDO);

        foreach ($this->realmLabels() as $realm => $label) {
            $this->assertTrue(PseudoLocale::isMarked($label), "{$realm}: {$label}");
        }
        foreach ($this->launcherTitles() as $realm => $title) {
            $this->assertTrue(PseudoLocale::isMarked($title), "{$realm}: {$title}");
        }
        $brand = Brand::for();
        $this->assertTrue(PseudoLocale::isMarked((string) $brand->tagline));
        $this->assertTrue(PseudoLocale::isMarked($brand->passkeyCopy));
        $this->assertStringContainsString(':title', (string) $brand->titleTemplate);
        $this->assertSame('Acme', $brand->name);
    }

    public function test_a_nav_section_keeps_its_header_seats_registrant_through_the_merge(): void
    {
        $registry = new NavSectionRegistry;
        $seat = fn (int $order, string $label): NavSection => new NavSection(
            key: 'billing', realm: 'tenant', label: $label, icon: 'card', href: '/billing', order: $order,
            entitlement: null, permission: null,
        );
        $registry->register($seat(1, 'Billing'), by: 'splicewire/laravel-beam');
        $registry->register($seat(5, 'Usage'), by: 'splicewire/tower');

        $merged = $registry->for('tenant');

        $this->assertCount(1, $merged);
        $this->assertSame('Billing', $merged[0]->label);
        $this->assertSame('splicewire/laravel-beam', $merged[0]->registrant());

        $this->lines(['vendor/beam/es/nav.php' => ['sections' => ['billing' => ['label' => 'Facturación']]]]);
        $this->app->setLocale('es');
        $this->assertSame('Facturación', $this->app->make(ShellText::class)->navSectionLabel('billing', $merged[0]->registrant(), 'Billing'));
    }

    public function test_a_nav_route_with_no_identity_renders_its_literal(): void
    {
        $this->lines(['vendor/beam/es/nav.php' => ['routes' => ['operator/home' => ['title' => 'Inicio']]]]);
        $this->app->setLocale('es');
        $shell = $this->app->make(ShellText::class);

        $this->assertSame('Inicio', $shell->navRouteTitle('operator.home', 'splicewire/laravel-beam', 'Home'));
        $this->assertSame('Home', $shell->navRouteTitle(null, 'splicewire/laravel-beam', 'Home'));
    }

    /** A soft-gated tenant realm with upsell copy, and a capability an overlay registers with its own upsell. */
    private function gatedRealmWithCapability(): void
    {
        config(['beam.core.realms.tenant.gate' => [
            'entitlement' => 'plan.pro', 'mode' => 'soft',
            'upsell' => ['title' => 'Go Pro', 'cta' => 'Upgrade', 'href' => '/billing', 'plan' => 'pro'],
        ]]);
        $this->app->make(RealmOverlayRegistry::class)->register(RealmOverlay::make(
            realmKey: 'tenant',
            overlay: ['overlay' => '1.0.0', 'actions' => []],
            capabilities: [['key' => 'reports', 'title' => 'Reports', 'entitlement' => 'plan.pro',
                'upsell' => ['title' => 'Unlock reports', 'url' => 'https://example.test/pro']]],
        ), by: 'splicewire/laravel-beam');
    }

    /** @return array<string, mixed> */
    private function tenantDescriptor(): array
    {
        return collect($this->app->make(RealmManifestProjector::class)->project(null))->firstWhere('key', 'tenant');
    }

    public function test_capability_titles_and_upsell_copy_render_unchanged_in_english(): void
    {
        $this->lines([]);
        $this->gatedRealmWithCapability();

        $tenant = $this->tenantDescriptor();

        $this->assertSame(['title' => 'Go Pro', 'cta' => 'Upgrade', 'href' => '/billing', 'plan' => 'pro'], $tenant['upsell']);
        $this->assertSame('Reports', $tenant['capabilities'][0]['title']);
        $this->assertSame(['title' => 'Unlock reports', 'url' => 'https://example.test/pro'], $tenant['capabilities'][0]['upsell']);
    }

    public function test_capability_titles_and_upsell_copy_read_their_keys_and_never_key_a_url_or_identifier(): void
    {
        $this->lines(['vendor/beam/es/realms.php' => ['tenant' => [
            'upsell' => ['title' => 'Hazte Pro', 'cta' => 'Mejorar', 'href' => '/nunca', 'plan' => 'nunca'],
            'capabilities' => ['reports' => ['title' => 'Informes', 'upsell' => ['title' => 'Desbloquea informes', 'url' => 'https://nunca']]],
        ]]]);
        $this->app->setLocale('es');
        $this->gatedRealmWithCapability();

        $tenant = $this->tenantDescriptor();

        $this->assertSame(['title' => 'Hazte Pro', 'cta' => 'Mejorar', 'href' => '/billing', 'plan' => 'pro'], $tenant['upsell']);
        $this->assertSame('Informes', $tenant['capabilities'][0]['title']);
        $this->assertSame(['title' => 'Desbloquea informes', 'url' => 'https://example.test/pro'], $tenant['capabilities'][0]['upsell']);
    }

    public function test_a_registrant_is_stamped_once_and_a_different_restamp_is_ignored_and_reported(): void
    {
        \Illuminate\Support\Facades\Exceptions::fake();
        $section = new NavSection(
            key: 'people', realm: 'tenant', label: 'People', icon: 'users', href: '/people', order: 1, entitlement: null, permission: null,
        );
        $registry = new NavSectionRegistry;

        $registry->register($section, by: 'splicewire/laravel-beam');
        $section->stampRegistrant('splicewire/laravel-beam');   // the same registrant again: silent
        $section->stampRegistrant('splicewire/tower');          // a different one: ignored, and reported

        $this->assertSame('splicewire/laravel-beam', $section->registrant());
        \Illuminate\Support\Facades\Exceptions::assertReportedCount(1);
        \Illuminate\Support\Facades\Exceptions::assertReported(fn (\LogicException $e): bool => str_contains($e->getMessage(), 'splicewire/tower'));
    }

    public function test_an_unrecognised_registrant_gets_no_derived_key_never_the_hosts(): void
    {
        // A host line with the very key an unnamespaced (host) fallback would form: it must NOT answer.
        $this->lines(['es/nav.php' => ['sections' => ['people' => ['label' => 'Gente (host)']]]]);
        $this->app->setLocale('es');
        $shell = $this->app->make(ShellText::class);

        $this->assertNull($shell->prefixOfRegistrant('not a registrant!'));
        $this->assertFalse(ShellText::isRecognised('not a registrant!'));
        $this->assertSame('People', $shell->navSectionLabel('people', 'not a registrant!', 'People'));
        // The host itself still reads its own key.
        $this->assertSame('Gente (host)', $shell->navSectionLabel('people', null, 'People'));

        $this->app->instance(NavSectionRegistry::class, $sections = new NavSectionRegistry);
        $sections->register(new NavSection(
            key: 'people', realm: 'tenant', label: 'People', icon: 'users', href: '/people', order: 1, entitlement: null, permission: null,
        ), by: 'not a registrant!');
        $findings = $this->app->make(\Splicewire\Beam\Doctor\LocalizationDeclarationAudit::class)->run();
        $unkeyable = array_values(array_filter($findings, fn ($f) => $f->check === \Splicewire\Beam\Doctor\LocalizationDeclarationAudit::UNKEYABLE));

        $this->assertSame('warn', $unkeyable[0]->status->value);
        $this->assertStringContainsString('not a registrant!', implode("\n", array_map(fn ($f) => $f->detail, $unkeyable)));
    }

    public function test_the_catalog_lists_realm_labels_nav_sections_and_brand_keys(): void
    {
        $this->lines([]);
        $this->app->instance(NavSectionRegistry::class, $sections = new NavSectionRegistry);
        $sections->register(new NavSection(
            key: 'people', realm: 'tenant', label: 'People', icon: 'users', href: '/people', order: 1, entitlement: null, permission: null,
        ), by: 'splicewire/laravel-beam');

        $keys = $this->app->make(KeyCatalog::class)->byKey();

        $this->assertSame('App', $keys['beam::realms.tenant.label']->english);
        $this->assertSame('People', $keys['beam::nav.sections.people.label']->english);
        $this->assertSame('Ship it', $keys['beam::brand.tagline']->english);
        $this->assertSame(':title · :name', $keys['beam::brand.title_template']->english);
        $this->assertArrayNotHasKey('beam::brand.name', $keys);
        $this->assertContains('realms', KeyCatalog::DERIVED_GROUPS);

        $this->gatedRealmWithCapability();
        $this->app->forgetInstance(KeyCatalog::class);
        $keys = $this->app->make(KeyCatalog::class)->byKey();
        $this->assertSame('Go Pro', $keys['beam::realms.tenant.upsell.title']->english);
        $this->assertSame('Upgrade', $keys['beam::realms.tenant.upsell.cta']->english);
        $this->assertArrayNotHasKey('beam::realms.tenant.upsell.href', $keys);
        $this->assertArrayNotHasKey('beam::realms.tenant.upsell.plan', $keys);
        $this->assertSame('Reports', $keys['beam::realms.tenant.capabilities.reports.title']->english);
        $this->assertSame('Unlock reports', $keys['beam::realms.tenant.capabilities.reports.upsell.title']->english);
    }
}
