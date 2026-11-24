<?php

namespace Splicewire\Beam\Agreements;

use Attribute;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

/**
 * Declares the typed Data projection that represents an Agreement's terms for a model-backed subject.
 *
 * Commerce packages can name a projection without depending on the Agreements seam. The seam serializes
 * the projection's data, rather than presentation text, when it freezes a version.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AgreementTerms
{
    /** @param class-string<Data> $data */
    public function __construct(public readonly string $data)
    {
        if (! class_exists($data) || ! is_subclass_of($data, Data::class)) {
            throw new InvalidArgumentException(sprintf(
                '%s must name a %s subclass; [%s] given.',
                self::class,
                Data::class,
                $data,
            ));
        }
    }
}
