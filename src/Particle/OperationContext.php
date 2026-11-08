<?php

namespace Splicewire\Beam\Particle;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use LogicException;

/**
 * What an operation's input may see while it is VALIDATED: the subject the operation acts on, the request, and the actor
 * (tutorial-dx 03). Immutable, and bound in the container only for the duration of validation, because laravel-data
 * resolves an input Data class's `rules()` before any DTO exists; a `rules(OperationContext $operation)` therefore sees the
 * subject without re-querying it. Name the parameter anything BUT `$context`: laravel-data passes its own
 * `ValidationContext` to `rules()` by that name (`DataValidationRulesResolver::resolveOverwrittenRules`). The subject
 * is null for an operation that declares none (`subject: NoSubject::class`).
 *
 * After validation a handler that opts in receives an {@see OperationCall}, which carries this context and the validated
 * DTO. Like {@see \Splicewire\Beam\Read\ReadContext} and {@see \Splicewire\Beam\Write\WriteContext}, it is per-request:
 * a Task's job must not capture it (copy the domain values the job needs instead).
 */
final class OperationContext
{
    public function __construct(
        public readonly mixed $subject,
        public readonly Request $request,
        public readonly ?Authenticatable $actor,
    ) {}

    /** Never queued: a Task job copies the domain values it needs (tutorial-dx 03). */
    public function __serialize(): array
    {
        throw new LogicException(OperationCall::NOT_QUEUED);
    }
}
