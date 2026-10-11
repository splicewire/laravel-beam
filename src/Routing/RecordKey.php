<?php

namespace Splicewire\Beam\Routing;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/** Reject malformed primary keys before a database driver has to interpret them. */
final class RecordKey
{
    /**
     * Enforce the key shape the model itself declares. A named route key is a separate public
     * identifier (usually a slug), so callers must invoke this only for primary-key lookups.
     *
     * @param  class-string<Model>  $model
     */
    public static function assertValidFor(string $model, string $value): void
    {
        if (! in_array(HasUuids::class, class_uses_recursive($model), true)) {
            return;
        }

        $instance = new $model;

        if (! in_array($instance->getKeyName(), $instance->uniqueIds(), true)) {
            return;
        }

        self::assertMatches(IdConstraint::Uuid, $value, $model);
    }

    /** Enforce a declared route constraint at request time as well as in the router. */
    public static function assertMatches(?IdConstraint $constraint, string $value, string $subject): void
    {
        if (! $constraint?->enforced() || Str::isUuid($value)) {
            return;
        }

        throw (new ModelNotFoundException)->setModel($subject, [$value]);
    }
}
