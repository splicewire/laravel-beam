<?php

namespace Splicewire\Beam\Webhooks\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\SuccessResponseData;

#[TypeScript]
class HookDeliveriesResponseData extends SuccessResponseData
{
    public function __construct(
        /** @var list<HookDeliveryData> */
        #[DataCollectionOf(HookDeliveryData::class)]
        public array $data,
    ) {}
}
