<?php

namespace Splicewire\Beam\Localization\Attributes;

use Attribute;

/**
 * A coded error case's English message, declared on the case (localization ticket 13, ruling 01 §4). The declaration
 * stays the English catalog, as every derived key's does: the translator answers the case's derived key
 * (`<ns>::enums.<Enum>.cases.<Case>.message`) in other languages, and this literal is the default when the ladder
 * misses. Named parameters use Laravel's `:name` form.
 *
 *   #[Message('Name at least one event to subscribe to.')]
 *   case EventsRequired = 'events_required';
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Message
{
    public function __construct(public string $text) {}
}
