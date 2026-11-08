<?php

namespace Splicewire\Beam\Particle;

use Closure;
use LogicException;
use ReflectionFunction;
use ReflectionNamedType;
use Spatie\LaravelData\Data;
use WeakMap;

/**
 * One validated invocation of an operation, for a handler that opts in (tutorial-dx 03): its {@see OperationContext}
 * (subject, request, actor) and the validated input DTO, made once by the controller, so the handler re-parses nothing.
 * `input` is null for an operation that declares no Data-class `input:`.
 *
 * A handler opts in by declaring ONE parameter typed `OperationCall`: `fn (OperationCall $call) => …`. A Stream handler
 * also receives the emitter as its second argument, `fn (OperationCall $call, Emitter $emit)`; the emitter is not a field,
 * so no field depends on the phase. Every other handler keeps the legacy `($model, $request, $actor[, $emit])` call,
 * unchanged. A Task's handler returns a job, which must copy the domain values it needs: a job that captures this call,
 * its context or the request is refused before dispatch, since none of them belongs on a queue.
 */
final class OperationCall
{
    /** @var WeakMap<Closure, bool>|null */
    private static ?WeakMap $wanted = null;

    public function __construct(
        public readonly OperationContext $context,
        public readonly ?Data $input,
    ) {}

    /**
     * Whether a handler opts in: its first parameter is typed exactly `OperationCall`. Reflected once per handler.
     *
     * Any other spelling that names `OperationCall` (a union, an intersection, a nullable, or a later parameter) is
     * refused rather than read as legacy: it would otherwise be called `($model, $request, $actor)` and fail, or
     * silently get the model, far from the declaration.
     */
    public static function wantedBy(Closure $handle): bool
    {
        self::$wanted ??= new WeakMap;

        if (! isset(self::$wanted[$handle])) {
            $function = new ReflectionFunction($handle);
            $wanted = false;

            foreach ($function->getParameters() as $position => $parameter) {
                $type = $parameter->getType();

                if ($type === null || ! str_contains((string) $type, self::class)) {
                    continue;
                }
                if ($position === 0 && $type instanceof ReflectionNamedType && $type->getName() === self::class && ! $type->allowsNull()) {
                    $wanted = true;

                    continue;
                }

                throw new LogicException(sprintf(
                    'The operation handler declared at %s:%d types its parameter $%s as [%s]. A handler opts in to the '
                    .'OperationCall with ONE first parameter typed exactly `OperationCall $call` (not a union, an intersection, '
                    .'a nullable or a later parameter); otherwise it keeps the legacy ($model, $request, $actor) call (tutorial-dx 03).',
                    $function->getFileName(), $function->getStartLine(), $parameter->getName(), $type,
                ));
            }

            self::$wanted[$handle] = $wanted;
        }

        return self::$wanted[$handle];
    }

    /** Never queued: a Task job copies the domain values it needs (tutorial-dx 03). */
    public function __serialize(): array
    {
        throw new LogicException(self::NOT_QUEUED);
    }

    /** @internal the refusal both per-request objects give when something tries to serialize them */
    public const NOT_QUEUED = 'The per-request OperationCall and its OperationContext must not be serialized: a Task job is '
        .'queued, so it must not capture them (or the Request). Copy the domain values it needs into the job instead (tutorial-dx 03).';
}
