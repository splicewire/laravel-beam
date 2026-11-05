<?php

use Splicewire\Beam\Events\EventType;
use Splicewire\Beam\Events\EventTypeRegistry;
use Splicewire\Beam\Webhooks\Data\EventTypeDescriptorData;

// A package declares one publishable event beside the ones Beam ships.
$catalog = app(EventTypeRegistry::class)->register(new EventType(
    name: 'hooks.paused',
    subject: Splicewire\Beam\Models\Hook::class,
    description: 'A hook stopped delivering until an operator resumes it.',
), null, 'acme/hooks');

// The catalog as `GET /hooks/events?resource=hooks` lists it: one descriptor per name, the resource split off.
return array_map(
    fn (EventType $type) => EventTypeDescriptorData::fromEventType($type),
    $catalog->withPrefix('hooks'),
);
