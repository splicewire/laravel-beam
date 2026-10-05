<?php

namespace Splicewire\Beam\Brand;

use Illuminate\Http\Request;

/**
 * Which brand a request is under. The default answers `beam.brand` for every request; a host or a package (DOCS-12's
 * `beam.brands` subtree override, docs-walkthrough C-1) rebinds this contract to answer per subtree.
 */
interface BrandResolver
{
    public function for(?Request $request = null): BrandData;
}
