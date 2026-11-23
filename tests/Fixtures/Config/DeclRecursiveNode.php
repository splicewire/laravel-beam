<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Config\Attributes\ConfigKey;

/** Fixture (Codex review lane A): `password` is a secret, and `children` is a list of this same record. */
class DeclRecursiveNode extends Data
{
    /** @param  array<int, DeclRecursiveNode>  $children */
    public function __construct(
        #[ConfigKey(secret: true)]
        public ?string $password,
        #[DataCollectionOf(DeclRecursiveNode::class)]
        public array $children,
    ) {}
}
