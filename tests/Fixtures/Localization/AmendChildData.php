<?php

namespace Splicewire\Beam\Tests\Fixtures\Localization;

use Schemastud\DataSchemas\Attributes\Title;
use Schemastud\Frame\Attributes\Column;

/**
 * REVIEW-B6 B3: the class a resource exposes. It inherits `$accountName` (keyed under AmendParentData, its declaring
 * class) and declares a label-less column, whose context key the widget-label hook still tries first.
 */
class AmendChildData extends AmendParentData
{
    #[Title('Display name')]
    #[Column]
    public string $displayName = '';
}
