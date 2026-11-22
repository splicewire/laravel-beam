<?php

namespace Splicewire\Beam\Tests\Fixtures\Config;

use Schemastud\DataSchemas\Attributes\MapValues;
use Spatie\LaravelData\Data;

/** Fixture (config-sections 20): a map of records nested below a union ({@see MailersSection::$group}). */
class MailerGroup extends Data
{
    /** @param  array<string, MailerEntry>  $mailers */
    public function __construct(
        #[MapValues(MailerEntry::class)]
        public array $mailers,
    ) {}
}
