<?php

namespace Splicewire\Beam\Particle\Contribution;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Spatie\LaravelData\Concerns\AppendableData;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Particle\ParticleListQuery;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Read\ReadContext;

/**
 * The ONE helper that folds contributed slices onto a projected row — called from both transports'
 * projection points, never reimplemented in either.
 *
 * There are two of those points and there have always been two:
 * {@see ParticleController::projectRecord()} (REST, list AND detail — `$project` fires on the list path)
 * and {@see ParticleFrameResourceHandler::projectRead()} (Frame's twin). Ticket 04 §A4 called for one
 * shared helper rather than two implementations precisely because this map has already watched the two
 * transports drift apart once: each had its own list base query and each implemented exactly the half
 * the other was missing, for as long as nobody owned the shared builder (ticket 05, fixed by
 * {@see ParticleListQuery}). Two hand-synced copies of a fold is the same obligation.
 *
 * ## Why `additional()`, and what it costs
 *
 * The slice lands via spatie's {@see AppendableData::additional()}, whose
 * keys serialize top-level alongside the owner's own properties. Two alternatives were rejected:
 *
 *  - declaring the key on the owner's Data class — that is a `commerce`-shaped property inside
 *    beam-tenancy, which closes the dependency cycle the seam guard exists to prevent (ticket 04 §A1);
 *  - overriding `with()` on a shared Data base class — rejected on a reason only grilling surfaced:
 *    `with()` cannot know WHICH RESOURCE KEY it is being projected for, and one Data class may serve
 *    more than one key.
 *
 * ⚠️ The known, accepted cost: `additional()` keys are invisible to laravel-data's TypeScript
 * transformer, so the slice generates a type of its own while the OWNER's generated type never grows the
 * key. That is the price of the guard, chosen knowingly (ticket 04 §A4) — not an oversight to repair here.
 */
class ContributionProjector
{
    public function __construct(protected ResourceContributionRegistry $contributions) {}

    /**
     * Fold every registered slice for `$key` onto `$data`, returning it unchanged when nobody
     * contributes — the inert default for all but a handful of resources estate-wide.
     *
     * Both halves of ticket 04 §A7's null rule live here: an includes-only contribution (no `value`
     * closure) contributes NO key, so its absence still means "not installed"; a `value` closure that
     * returns null contributes the key WITH null, so a caller can tell an uninstalled package from a
     * record that simply has no slice.
     *
     * @param  array<string, mixed>  $filters  the opaque facet bag (`filter[...]`), forwarded verbatim —
     *                                         beam does not interpret it, and it is what carries
     *                                         `filter[period]` (ticket 05 §A3; the `ReadContext` provably
     *                                         cannot).
     */
    public function apply(string $key, Data $data, Model $record, ReadContext $ctx, array $filters = []): Data
    {
        if (! $this->contributions->has($key)) {
            return $data;
        }

        $slices = [];

        foreach ($this->contributions->for($key) as $contribution) {
            if ($contribution->value === null) {
                continue;
            }

            $slice = ($contribution->value)($record, $ctx, $filters);

            if ($slice !== null && ! $slice instanceof $contribution->data) {
                throw new RuntimeException(
                    "Resource contribution [{$key}.{$contribution->as}] declares ["
                    .$contribution->data.'] but its value arm returned ['.$slice::class.'].'
                );
            }

            // ⚠️ Transformed HERE, not left as a Data object. spatie merges additional data RAW —
            // `TransformedDataResolver` does `array_merge($transformed, $data->getAdditionalData())` with
            // no transformation pass over the appended values — so a `Data` left in the bag survives
            // `toArray()` as a live object. Over REST that is invisible (the envelope JSON-encodes it and
            // `Data` is JsonSerializable), but Frame's projection contract IS an array, and its handler
            // returns the row straight to the socket. Left raw, the same contribution reaches the two
            // transports as two different shapes — the drift this seam's ONE shared helper exists to
            // prevent, arriving through the back door.
            $slices[$contribution->as] = $slice?->toArray();
        }

        return $slices === [] ? $data : $data->additional($slices);
    }

    /**
     * The STATIC contributed includes for `$key` — the arm
     * {@see ParticleResourceRegistry::get()} folds into the owner's declared
     * list, so both transports pick it up on every read path (list, detail and subject resolution) from
     * the one lookup they already share.
     *
     * A contribution whose includes arm is a Closure is skipped here BY DESIGN: `get(string $key)` has no
     * request in scope and must stay a pure declaration lookup. That arm resolves in
     * {@see dynamicIncludes()} instead.
     *
     * @return list<string>
     */
    public function staticIncludes(string $key): array
    {
        $includes = [];

        foreach ($this->contributions->for($key) as $contribution) {
            if (! $contribution->isDynamic()) {
                $includes = [...$includes, ...$contribution->includes()];
            }
        }

        return array_values(array_unique($includes));
    }

    /**
     * The REQUEST-PARAMETERIZED contributed includes for `$key` — the constrained-eager-load arm, resolved
     * ONCE PER REQUEST against the facet bag.
     *
     * ⚠️ Once per request, never per record. Resolving this inside a row loop reproduces the exact defect
     * the includes arm exists to remove, which is why the only caller is the shared list builder
     * ({@see ParticleListQuery::forList()}) rather than either projection point.
     *
     * ⚠️ String keys are PRESERVED — a constrained eager-load is a `relation => Closure` map, and
     * `array_values()`ing it would drop the constraint while still loading the relation, which is worse
     * than not folding it at all. `array_merge` (not the spread) for the same reason: the spread operator
     * renumbers string keys under PHP's list semantics only for integer keys, but merge is the shape that
     * says "later contribution wins for the same relation" out loud.
     *
     * @param  array<string, mixed>  $filters
     * @return array<array-key, string|\Closure>
     */
    public function dynamicIncludes(string $key, array $filters): array
    {
        $includes = [];

        foreach ($this->contributions->for($key) as $contribution) {
            if ($contribution->isDynamic()) {
                $includes = array_merge($includes, $contribution->includes($filters));
            }
        }

        return $includes;
    }
}
