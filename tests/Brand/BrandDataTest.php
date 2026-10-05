<?php

namespace Splicewire\Beam\Tests\Brand;

use Illuminate\Http\Request;
use Splicewire\Beam\Brand\Brand;
use Splicewire\Beam\Brand\BrandData;
use Splicewire\Beam\Brand\BrandResolver;
use Splicewire\Beam\Tests\TestCase;

/**
 * `beam.brand` (ux-walkthrough M8, IA-14) is the family's ONE brand declaration, read through a RESOLVER
 * (`Brand::for()`, docs-walkthrough C-1). Hosts and the SPA carry it, and none of them authors a brand string. The case it
 * exists for: a non-Splicewire install must never tell its users that Splicewire is the processor.
 */
class BrandDataTest extends TestCase
{
    public function test_an_unconfigured_install_is_named_after_the_app_and_declares_no_legal_entity(): void
    {
        config(['app.name' => 'Acme Studio', 'beam.brand' => []]);

        $brand = Brand::for();

        $this->assertSame('Acme Studio', $brand->name);
        $this->assertNull($brand->legalEntity);
        $this->assertSame("Passkeys secure your Acme Studio login. They're tied to your account.", $brand->passkeyCopy);
        $this->assertStringNotContainsString('Splicewire', json_encode($brand->toArray()));
    }

    public function test_the_shape_is_m8_exactly(): void
    {
        config(['beam.brand' => ['name' => 'Acme', 'legal_entity' => 'Acme Ltd', 'logo' => '/logo.svg', 'title_template' => ':title · Acme', 'passkey_copy' => 'Use a passkey.']]);

        $this->assertSame(
            ['name' => 'Acme', 'logo' => '/logo.svg', 'titleTemplate' => ':title · Acme', 'legalEntity' => 'Acme Ltd', 'passkeyCopy' => 'Use a passkey.', 'contact' => ['sales' => null]],
            Brand::for()->toArray(),
        );
    }

    public function test_the_sales_contact_is_the_install_s_own_and_absent_unless_declared(): void
    {
        // purchase-walkthrough BUY-08 / BQ-9: the upsell's "talk to us" address is the install's, never a literal in a
        // surface. Unset, there is none: a non-Splicewire install must not route its buyers to Splicewire.
        config(['beam.brand' => ['name' => 'Acme']]);
        $this->assertSame(['sales' => null], Brand::for()->toArray()['contact']);

        config(['beam.brand' => ['name' => 'Acme', 'contact' => ['sales' => 'sales@acme.test']]]);
        $this->assertSame('sales@acme.test', Brand::for()->contact['sales']);
    }

    public function test_readers_get_whatever_resolver_is_bound_so_a_subtree_brand_can_land_later(): void
    {
        config(['beam.brand' => ['name' => 'Install-wide']]);
        $this->app->bind(BrandResolver::class, fn () => new class implements BrandResolver
        {
            public function for(?Request $request = null): BrandData
            {
                return BrandData::fromConfig(['name' => str_starts_with((string) $request?->path(), 'beam/docs') ? 'Beam' : 'Splicewire']);
            }
        });

        $this->assertSame('Beam', Brand::for(Request::create('/beam/docs/install'))->name);
        $this->assertSame('Splicewire', Brand::for(Request::create('/docs'))->name);
    }

    public function test_the_package_config_ships_no_literal_brand(): void
    {
        $shipped = require __DIR__.'/../../config/beam/brand.php';

        $this->assertStringNotContainsString('Splicewire', json_encode($shipped));
        $this->assertStringNotContainsString('Laravel', json_encode($shipped));
    }
}
