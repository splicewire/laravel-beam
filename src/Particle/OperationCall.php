<?php

namespace Splicewire\Beam\Particle;

use Closure;
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

    /** Whether a handler opts in: its first parameter is typed `OperationCall`. Reflected once per handler. */
    public static function wantedBy(Closure $handle): bool
    {
        self::$wanted ??= new WeakMap;

        if (! isset(self::$wanted[$handle])) {
            $first = (new ReflectionFunction($handle))->getParameters()[0] ?? null;
            $type = $first?->getType();
            self::$wanted[$handle] = $type instanceof ReflectionNamedType && $type->getName() === self::class;
        }

        return self::$wanted[$handle];
    }
}
