<?php

namespace Splicewire\Beam\Filters\Data;

use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Transformers\Transformer;

/** Query parameters are a map on the wire, including an empty saved view. */
class QueryParametersTransformer implements Transformer
{
    public function transform(DataProperty $property, mixed $value, TransformationContext $context): object
    {
        return (object) $value;
    }
}
