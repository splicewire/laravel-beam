<?php

namespace Splicewire\Beam\Write\Dedupe;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Splicewire\Beam\Concerns\Deduplicates;
use Splicewire\Beam\Schema\Keywords;

/**
 * Thrown when a schema declares `x-beam-dedupe` against a model that has not opted in — it does not
 * compose {@see Deduplicates}, so its table carries no `dedupe_key` column (beam-facade ticket 50
 * §11).
 *
 * This is a WIRING error, not a request error: the pairing of a schema and a model is a host's
 * declaration, so it is right that it surfaces as a 500 and not as a status code a submitter sees.
 *
 * It throws rather than no-opping on purpose. Nine models compose `PersistsBeamParticle` and the
 * dedupe stage is in the shipped default chain, so a mis-declared keyword is reachable rather than
 * theoretical — and a keyword that quietly does nothing is worse than one that refuses (ticket 40's
 * `x-beam-notify` defect: effective for the payload, inert for notify, wrong for a year, flagged by
 * nothing).
 */
class DedupeNotSupported extends RuntimeException
{
    public static function for(Model|string $subject): self
    {
        $type = $subject instanceof Model ? $subject::class : $subject;

        return new self(
            'A schema declares ['.Keywords::Dedupe."] against [{$type}], which does not compose ["
            .Deduplicates::class.'] and has no dedupe_key column. Compose the trait and add the '
            .'nullable indexed column, or remove the keyword from the schema.'
        );
    }
}
