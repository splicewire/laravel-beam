<?php

namespace Splicewire\Beam\Write;

use RuntimeException;
use Splicewire\Beam\Events\BeamParticlePersisted;
use Splicewire\Beam\Particle\Backing\ResolvedRecord;
use Splicewire\Beam\Particle\Backing\WritableRecord;
use Splicewire\Beam\Particle\Backing\WritesRecords;

/**
 * Thrown when a {@see WritableRecord} carries a subject the write PIPELINE cannot persist — anything
 * that is not an `Illuminate\Database\Eloquent\Model` (particle-write-surface ticket 07).
 *
 * ## Why this exists, and why it is a feature rather than a leak
 *
 * The ruling behind ticket 07 is that *"a backing exists so data can be sourced from and written to any
 * number of places — not just Eloquent models."* {@see WritesRecords} now honours that at the CAPABILITY
 * boundary: it traffics in {@see WritableRecord}, whose `$subject` is `object`, mirroring what
 * {@see ResolvedRecord} already did for the read side.
 *
 * The pipeline BELOW that boundary has not moved yet, and deliberately so — {@see WriteContext::$model},
 * {@see Stages\PersistStage}'s `fill()`/`save()`, the gate's policy subject, and
 * {@see BeamParticlePersisted}'s `public Model $record` (59 files across 6 family
 * packages and 7 hosts, measured 2026-08-31) are five separate seams with estate-wide consumer sets. That
 * migration is a map, not a ticket.
 *
 * So this exception is the **single named place** the remaining Eloquent requirement lives. That is the
 * whole value of the envelope: before it, the requirement was spelled twice in {@see WritesRecords}'s own
 * return types, which made a non-model writing backing *unsatisfiable* rather than merely unfinished —
 * and the population of such backings was, accordingly, zero.
 *
 * ⚠️ **A `TypeError` here would have been the estate's recurring defect class in signature form** — a
 * constraint that fails without saying what it wanted. This names the subject, names the requirement, and
 * names where the work is, so a caller that hits it learns the truth instead of a stack frame.
 *
 * When it is raised, NOTHING has persisted.
 */
class WriteSubjectNotEloquent extends RuntimeException
{
    public static function for(object $subject): self
    {
        return new self(
            'The beam write pipeline can only persist an Eloquent model, and this WritableRecord carries ['
            .$subject::class.'].'
            .' The capability boundary (WritesRecords) is persistence-agnostic; the pipeline below it'
            .' (WriteContext, PersistStage, the write gate, BeamParticlePersisted) is not yet.'
        );
    }
}
