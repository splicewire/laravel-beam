<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\MapValues;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use Splicewire\Beam\Config\Attributes\ConfigKey;
use Splicewire\Beam\Enums\ConfigTier;

/**
 * Fixture (config-sections 20 r3-r5 / 19): one relay in a LIST of records; `password` is a secret a record may leave out,
 * and `creds` is a map of records nested INSIDE the list item.
 */
class RelayEntry extends Data
{
    /** @param  array<string, CredEntry>|Optional  $creds */
    public function __construct(
        #[ConfigKey(ceiling: ConfigTier::Operator)]
        public string $host,
        #[ConfigKey(ceiling: ConfigTier::Operator, secret: true)]
        public string|Optional|null $password,
        #[MapValues(CredEntry::class)]
        public array|Optional $creds,
    ) {}
}
