<?php

namespace Splicewire\Beam\Brand;

use Illuminate\Http\Request;
use Splicewire\Beam\Localization\BeamTranslator;
use Splicewire\Beam\Localization\DeclaredText;
use Splicewire\Beam\Localization\TextNamespaces;

/**
 * The one way to read the brand: `Brand::for($request)`. Carriers (the Inertia `brand` share, the SPA's runtime config)
 * call it, and it resolves the bound {@see BrandResolver}, by default `beam.brand` ({@see ConfigBrandResolver}).
 *
 * **Brand copy reads in the request's language** (localization 11; ruling 01 §3, row "brand copy"): three explicit keys
 * in BEAM's namespace, resolved here, at emit, with the host's configured value as the default:
 *
 * - `beam::brand.tagline`;
 * - `beam::brand.passkey_copy` (`:name` is the brand name);
 * - `beam::brand.title_template` (its `:title` and `:name` placeholders are the renderer's, left untouched).
 *
 * `name` and `legalEntity` are proper nouns and are not keyed. A host overrides a line in
 * `lang/vendor/beam/<locale>/brand.php`.
 */
final class Brand
{
    public static function for(?Request $request = null): BrandData
    {
        return self::localized(app(BrandResolver::class)->for($request));
    }

    /**
     * A product's brand (docs-walkthrough DOCS-12, C-1 and lead ruling 1): `beam.brands[product]`, keyed by a docs root's
     * declared product, else the install brand. One install serving two products (www: Splicewire at `/`, Beam at
     * `/beam/docs`) declares both here; an install with one product declares none and reads `beam.brand`.
     */
    public static function forProduct(?string $product): BrandData
    {
        $declared = $product === null ? null : config("beam.brands.{$product}");

        return is_array($declared) ? self::localized(BrandData::fromConfig($declared)) : self::for();
    }

    /** The brand with its keyed copy resolved for the current locale; unchanged when beam's namespace has no keys. */
    private static function localized(BrandData $brand): BrandData
    {
        if (! app()->bound('translator') || ! app('translator') instanceof BeamTranslator) {
            return $brand;
        }
        $prefix = app(TextNamespaces::class)->ownerOfPackage('splicewire/laravel-beam')->keyPrefix();
        if ($prefix === null) {
            return $brand;
        }
        $text = app(DeclaredText::class);

        return new BrandData(
            name: $brand->name,
            logo: $brand->logo,
            titleTemplate: $brand->titleTemplate === null ? null : $text->resolve($prefix.'brand.title_template', $brand->titleTemplate),
            legalEntity: $brand->legalEntity,
            passkeyCopy: (string) $text->resolve($prefix.'brand.passkey_copy', $brand->passkeyCopy, null, ['name' => $brand->name]),
            contact: $brand->contact,
            home: $brand->home,
            tagline: $brand->tagline === null ? null : $text->resolve($prefix.'brand.tagline', $brand->tagline),
        );
    }
}
