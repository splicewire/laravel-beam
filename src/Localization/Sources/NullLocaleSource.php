<?php

namespace Splicewire\Beam\Localization\Sources;

use Illuminate\Http\Request;
use Splicewire\Beam\Localization\Contracts\BrandLocaleSource;
use Splicewire\Beam\Localization\Contracts\PersonLocaleSource;
use Splicewire\Beam\Localization\Contracts\TenantLocaleSource;

/** The default for a slot whose owning package has not contributed its adapter: it states no language. */
final class NullLocaleSource implements BrandLocaleSource, PersonLocaleSource, TenantLocaleSource
{
    public function locale(?Request $request, mixed $recipient = null): ?string
    {
        return null;
    }
}
