<?php

namespace Splicewire\Beam\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One in-process signal for a declared Beam event producer.
 *
 * The payload remains the producer's in-process array shape. HTTP projections use the event's declared
 * catalog Data class instead of exposing this array directly. A null subject is valid for an explicitly
 * subjectless catalog event; current Beam hook lifecycle entries carry a Hook model.
 *
 * @api
 */
class BeamEventOccurred
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventName,
        public ?Model $subject,
        public array $payload,
    ) {}
}
