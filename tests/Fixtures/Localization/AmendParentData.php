<?php

namespace Splicewire\Beam\Tests\Fixtures\Localization;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;

/** REVIEW-B6 B3: a parent Data class whose titled property a child inherits. */
class AmendParentData extends Data
{
    public function __construct(
        #[Title('Account name')]
        public string $accountName = '',
    ) {}
}
