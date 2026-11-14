<?php

namespace Splicewire\Beam\Localization\Client;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/** Why the client-catalog endpoint refused a request (localization 16; ticket 13's pattern). */
enum ClientCatalogError: string implements CodedError
{
    use IsCodedError;

    #[Message('No client catalog is served for the locale [:locale].')]
    case LocaleUnsupported = 'client_catalog_locale_unsupported';

    #[Message('No client catalog is served for the realm [:realm].')]
    case RealmUnknown = 'client_catalog_realm_unknown';

    #[Message('You may not read the client catalog for the realm [:realm].')]
    case RealmForbidden = 'client_catalog_realm_forbidden';
}
