<?php

namespace Splicewire\Beam\Tests\Data;

use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The same read-projection cases on a host whose global OUTPUT mapper is snake: the camel keys must not float. */
class ReadProjectionWireNameSnakeOutputHostTest extends ReadProjectionWireNameTest
{
    protected function hostOutputMapper(): ?string
    {
        return SnakeCaseMapper::class;
    }
}
