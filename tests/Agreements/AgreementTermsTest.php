<?php

namespace Splicewire\Beam\Tests\Agreements;

use InvalidArgumentException;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Agreements\AgreementTerms;
use Splicewire\Beam\Tests\TestCase;

class AgreementTermsTest extends TestCase
{
    public function test_it_accepts_a_typed_data_projection(): void
    {
        $attribute = new AgreementTerms(AgreementTermsProjection::class);

        $this->assertSame(AgreementTermsProjection::class, $attribute->data);
    }

    public function test_it_refuses_a_class_that_is_not_a_data_projection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must name a Spatie\\LaravelData\\Data subclass');

        new AgreementTerms(AgreementTermsNonData::class);
    }
}

class AgreementTermsProjection extends Data
{
    public function __construct(public string $price) {}
}

class AgreementTermsNonData {}
