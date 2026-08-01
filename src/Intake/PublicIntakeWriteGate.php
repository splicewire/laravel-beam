<?php

namespace Splicewire\Beam\Intake;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Write\Contracts\WriteGate;

/**
 * The PERMISSIVE-within-an-allow-list {@see WriteGate} for the public intake door (beam-write-pipeline
 * ticket 04). This is the "publicly submittable" binding the port's doctrine describes: it does NOT
 * delegate to policies (an anonymous stranger has none) — instead it permits a write ONLY when the
 * record's schema stem is on an explicit allow-list, and DENIES everything else.
 *
 * Deny-by-default is preserved and load-bearing (DESIGN §7 L5): a schema absent from the allow-list is
 * refused, so no record type is ever anonymously writable unless a host EXPLICITLY marks it public.
 * "Permissive" means "no policy required", never "open" — the allow-list is the whole authorization.
 */
class PublicIntakeWriteGate implements WriteGate
{
    /**
     * @param  list<string>  $publicSchemas  the stems/`$id`s a host has marked publicly submittable
     */
    public function __construct(private array $publicSchemas) {}

    public function authorizes(Model|string $subject, array $payload, mixed $actor = null): bool
    {
        $stem = $this->stemOf($subject);

        if ($stem === null) {
            return false;
        }

        foreach ($this->publicSchemas as $allowed) {
            // Match either the bare stem or a versioned `$id` marked public (compared by stem).
            if ($stem === SchemaId::from($allowed)->recordType()) {
                return true;
            }
        }

        return false;
    }

    /** The schema stem the subject writes under, or null when it carries no binding. */
    private function stemOf(Model|string $subject): ?string
    {
        if ($subject instanceof Model) {
            return method_exists($subject, 'recordType') ? $subject->recordType() : null;
        }

        return $subject === '' ? null : SchemaId::from($subject)->recordType();
    }
}
