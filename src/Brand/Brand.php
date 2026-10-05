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
}
