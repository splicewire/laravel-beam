<?php

namespace Splicewire\Beam\Events;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * The single post-persist signal every beam write path emits (beam-write-pipeline ticket 03).
 *
 * One event, three producers: the public intake route (ticket 04), Frame's authenticated editor
 * socket (ticket 06), and — incrementally — the app's CRUD controllers (tickets 09/10) all persist
 * through {@see ParticleWriter}, which dispatches THIS after a successful write.
 * Reactive concerns (notification — ticket 05; audit; indexing) subscribe here ONCE instead of being
 * re-hand-rolled per populator (the generation populator also emits it, per DESIGN §7 L8, so "one
 * signal for every write" is true across all producers).
 *
 * It carries the persisted model, the schema-shaped payload that was written, and the schema binding
 * (`schema_ref` — a bare stem or a versioned `$id`) the record was written under, or null for a record
 * type that carries no schema binding (a plain app model riding the pipeline).
 */
class BeamParticlePersisted
{
    /**
     * @param  array<string, mixed>  $payload  the schema-shaped content that was written
     */
    public function __construct(
        public Model $record,
        public array $payload,
        public ?string $schemaRef = null,
    ) {}
}
