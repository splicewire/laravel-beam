<?php

namespace Splicewire\Beam\Localization\Sources;

use Illuminate\Http\Request;
use Splicewire\Beam\Localization\Contracts\BrandLocaleSource;

/**
 * The brand's default language, read from `beam.localization.brand_default`: ticket 11's config-backed server adapter
 * (ruling 03, owner decision (c); REVIEW-B7 B1).
 *
 * - Null, or a blank value, states no language.
 * - Whether the tag is supported is the resolver's call ({@see \Splicewire\Beam\Localization\LocaleResolver}), as for
 *   every other slot, so an unsupported value falls through to the application default.
 * - BrandData and the shared props are unchanged: the language is a server source, not a brand field.
 */
final class ConfigBrandLocaleSource implements BrandLocaleSource
{
    public function locale(?Request $request, mixed $recipient = null): ?string
    {
        $configured = config('beam.localization.brand_default');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }
}
