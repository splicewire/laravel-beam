<?php

namespace Splicewire\Beam\Webhooks\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\SuccessResponseData;

#[TypeScript]
class EventCatalogResponseData extends SuccessResponseData
{
    public function __construct(public EventCatalogData $data) {}
}
