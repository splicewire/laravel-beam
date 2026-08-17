<?php

namespace Splicewire\Beam\Rendering\Subjects;

use RuntimeException;

/**
 * The default subject resolver: `Subject::with($with)->findOrFail($id)`, reached by duck-typing rather
 * than by importing Eloquent, so the engine keeps its no-`illuminate/database` topology guard honest.
 *
 * Eager loads are applied when the class understands `with`, and skipped otherwise — the macro passes
 * them so a migrated endpoint keeps the query shape it had (an export that eager-loaded its cells still
 * does), without the resolver assuming a query builder exists.
 */
class FindOrFailSubjectResolver implements ResolvesRenderingSubject
{
    public function resolve(string $subject, string $id, array $with = []): object
    {
        // Also accepts query(): Eloquent's findOrFail is never a literally declared method — Model
        // forwards it through __callStatic() to the query builder at runtime — so
        // method_exists($subject, 'findOrFail') is false for every real Eloquent model, and a guard
        // on findOrFail() alone would always throw for one. query() IS a real declared static method
        // on Model, so it reliably signals "Eloquent-shaped" without importing Eloquent by name. A
        // duck-typed, non-Eloquent store (no query()) still passes by declaring findOrFail() for real.
        if (! method_exists($subject, 'query') && ! method_exists($subject, 'findOrFail')) {
            throw new RuntimeException(sprintf(
                'Rendering subject [%s] has no findOrFail(); bind a %s for this store.',
                $subject,
                ResolvesRenderingSubject::class,
            ));
        }

        if ($with !== [] && method_exists($subject, 'with')) {
            return $subject::with($with)->findOrFail($id);
        }

        return $subject::findOrFail($id);
    }
}
