<?php

namespace Splicewire\Beam\Tests\Fixtures\Localization;

use Schemastud\DataSchemas\Contracts\ProvidesEnumLabel;

/** localization 09 fixture: an enum with its own English labels. */
enum CadenceEnum: string implements ProvidesEnumLabel
{
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Every day',
            self::Weekly => 'Every week',
        };
    }
}
