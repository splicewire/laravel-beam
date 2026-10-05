<?php

namespace Splicewire\Beam\Brand;

use Illuminate\Http\Request;

/** The install-wide brand from `config('beam.brand')`, the same for every request. */
final class ConfigBrandResolver implements BrandResolver
{
    public function for(?Request $request = null): BrandData
    {
        return BrandData::fromConfig((array) config('beam.brand', []));
    }
}
