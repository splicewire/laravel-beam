<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Spatie\LaravelData\Data;

/** Fixture (Codex review lane A): the declared type, whose `token` declares nothing. */
abstract class DeclInheritedBaseCreds extends Data
{
    public function __construct(
        public ?string $token,
    ) {}
}
