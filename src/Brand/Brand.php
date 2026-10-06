<?php

namespace Splicewire\Beam\Brand;

use Illuminate\Http\Request;

/**
 * The one way to read the brand: `Brand::for($request)`. Carriers (the Inertia `brand` share, the SPA's runtime config)
 * call it, and it resolves the bound {@see BrandResolver}, by default `beam.brand` ({@see ConfigBrandResolver}).
 */
final class Brand
{
    public static function for(?Request $request = null): BrandData
    {
        return app(BrandResolver::class)->for($request);
    }

    /**
     * A product's brand (docs-walkthrough DOCS-12, C-1 and lead ruling 1): `beam.brands[product]`, keyed by a docs root's
     * declared product, else the install brand. One install serving two products (www: Splicewire at `/`, Beam at
     * `/beam/docs`) declares both here; an install with one product declares none and reads `beam.brand`.
     */
    public static function forProduct(?string $product): BrandData
    {
        $declared = $product === null ? null : config("beam.brands.{$product}");

        return is_array($declared) ? BrandData::fromConfig($declared) : self::for();
    }
}
