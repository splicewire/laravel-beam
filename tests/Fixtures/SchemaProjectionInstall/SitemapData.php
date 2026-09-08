<?php

namespace Splicewire\Beam\Tests\Fixtures\SchemaProjectionInstall;

use Spatie\LaravelData\Data;

class SitemapData extends Data
{
    public function __construct(
        public string $label,
        public string $href,
        public int $order = 0,
        public ?string $externalUrl = null,
    ) {}
}
