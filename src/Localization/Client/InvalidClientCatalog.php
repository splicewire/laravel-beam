<?php

namespace Splicewire\Beam\Localization\Client;

use InvalidArgumentException;

/** JSON the client-catalog bridge refuses to admit, or an artifact that fails its shape or digest (localization 16). */
final class InvalidClientCatalog extends InvalidArgumentException {}
