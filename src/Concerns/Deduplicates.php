<?php

namespace Splicewire\Beam\Concerns;

use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Write\Dedupe\DedupeNotSupported;
use Splicewire\Beam\Write\Stages\DedupeStage;

/**
 * The opt-in marker for a model whose table carries a `dedupe_key` column (beam-facade ticket 50
 * §11). Composing it is what makes `x-beam-dedupe` legal against this model.
 *
 * It exists because {@see DedupeStage} runs in the SHIPPED DEFAULT write chain, and nine models
 * compose {@see PersistsBeamParticle}, each keeping its own table — `BeamParticle`,
 * `BeamSubmission`, threads' `Thread`/`Message`, `BeamUxEntry`, timeline's `Clip`/`Project`/`Track`,
 * satellite's `PersistsGeneratedComposition`. A schema declaring the keyword for a `Thread` would
 * otherwise stamp a column that table does not have. So the stage checks for this trait and throws
 * {@see DedupeNotSupported} when it is absent — NOT a silent no-op, which is ticket 40's exact
 * failure mode.
 *
 * Only the two base particle tables carry the column today: `beam_particles` and
 * `beam_submissions`. A host model that wants dedupe adds the nullable indexed column to its own
 * table and composes this trait.
 *
 * @mixin Model
 */
trait Deduplicates
{
    /**
     * The column holding the match key. Nullable and plainly indexed — a real column and not a
     * `meta` key, because "how many distinct captures" is a page a host renders, and a JSON-path
     * query indexes differently across sqlite-in-tests and MySQL (ticket 50 §10).
     */
    public function dedupeKeyColumn(): string
    {
        return 'dedupe_key';
    }

    /**
     * The capture universe this row's key is comparable within — the value folded INTO the hash.
     *
     * Named by ROLE, never by column name: the default reads the model's CAPTURE KIND
     * (`capture_key`), which ticket 65 renamed out from under three tickets that had hardcoded its
     * old name. Note what that value actually is — ticket 65's census found roughly half its writers
     * write a source-qualified capture kind from no route at all (`embed/escalation`,
     * `demo/guest-intake`), so it is a capture KIND and not an intake slug. Those are still distinct
     * capture universes, which is all the scope needs to be.
     *
     * A model with no capture-kind column (the bare `BeamParticle`) falls back to its stable morph
     * alias, so every trait-bearing model has a total, non-empty scope. Override to widen or narrow
     * it; the cross-intake question — the same address on two different forms — is the trigger to
     * revisit the default (ticket 50 §9).
     */
    public function dedupeScope(): string
    {
        $kind = $this->getAttribute('capture_key');

        return is_string($kind) && $kind !== '' ? $kind : $this->getMorphClass();
    }
}
