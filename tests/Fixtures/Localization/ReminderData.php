<?php

namespace Splicewire\Beam\Tests\Fixtures\Localization;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Data;

/** localization 09 fixture: declared English on the class, on two properties, and through an enum's labels. */
#[Title('Reminder')]
#[Description('A note that comes back.')]
class ReminderData extends Data
{
    public function __construct(
        #[Title('Subject')]
        #[Description('What the reminder is about.')]
        public string $subject,
        #[Title('Repeats')]
        public CadenceEnum $cadence,
    ) {}
}
