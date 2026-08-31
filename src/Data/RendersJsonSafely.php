<?php

namespace Splicewire\Beam\Data;

use BackedEnum;
use Closure;
use Illuminate\Http\JsonResponse;
use Throwable;
use UnitEnum;

/**
 * Render a payload as JSON without ever letting the ENCODING failure become the response.
 *
 * Measured at the flagship (api-surface-coherence 109): a 500 whose real cause was
 * `SQLSTATE[42P01]: relation "beam_hooks" does not exist` surfaced instead as
 * `Non-backed enums have no default serialization`, thrown from `JsonResponse.php:91` while the
 * error envelope was being built. The debug payload carried the exception's stack trace WITH its
 * arguments, and one argument was a pure enum. The envelope's own failure replaced the error it
 * existed to report — and named the framework, sending diagnosis at entirely the wrong thing.
 *
 * Non-backed enums are one instance of a class, not the class itself: closures, resources,
 * recursive structures, invalid UTF-8 byte strings and objects with a throwing `jsonSerialize()`
 * all break `json_encode` identically. A fix that special-cases enums leaves the trap armed, so
 * this one is indifferent to argument type. Four stages, each strictly weaker than the last:
 *
 *  0. **Project, when handed a projection rather than an array.** `toResponseArray()` is spatie's
 *     `toArray()` — a transformer pipeline over live property values, which can throw for the SAME
 *     reasons the encoder can (an unserializable property reached during transformation). Evaluating
 *     it at the CALL SITE, outside this method, put the projection outside the guarantee: the
 *     defence covered the encode and not the step that produced what it encodes. Callers therefore
 *     hand in a {@see Closure}, and a projection that throws falls back to the object's own declared
 *     state via `get_object_vars()` — properties read directly, without re-entering the pipeline
 *     that just failed — sanitised through {@see self::toJsonSafeValue()}, which cannot throw. An
 *     array may still be passed for a payload that was never a projection.
 *  1. **Encode as-is.** The happy path is byte-identical to `new JsonResponse($payload, $status)`,
 *     so nothing that already worked changes shape or cost. Sanitising unconditionally would have
 *     meant rewriting every well-formed response to repair a payload that is almost never broken.
 *  2. **Sanitise and retry**, only once stage 1 has actually thrown. A projection is substituted
 *     for whatever could not be encoded — `Enum::Case` for a pure enum, `{closure}`, `{resource}`,
 *     `{object(Class)}` — because by this point the payload is a DEBUG AFFORDANCE whose fidelity
 *     is worth strictly less than the message it is attached to. Objects that encode cleanly on
 *     their own are still passed through intact.
 *  3. **Fall back to a minimal envelope** carrying the status and a scrubbed message. Reached only
 *     if stage 2 somehow fails too; it touches nothing but scalars, so it cannot throw.
 *
 * The invariant the tests pin is the whole point: whatever is in the payload — and whatever happens
 * while the payload is being PROJECTED — the ORIGINAL message survives to the client.
 */
trait RendersJsonSafely
{
    /**
     * Maximum nesting the sanitiser will descend before substituting a marker. Guards the
     * recursive-structure case, which `json_encode` reports as "Recursion detected".
     */
    private const SAFE_ENCODE_MAX_DEPTH = 24;

    /**
     * The last-resort body when the PROJECTION itself threw — the object's own state, read without
     * re-entering the pipeline that just failed.
     *
     * **Public properties only, and no `_`-prefixed keys.** `get_object_vars($this)` runs in object
     * scope, so it returns protected and private state too, and that is a disclosure rather than a
     * recovery: a projection is not just a transformation, it is also a FILTER. `ResponseBody`'s
     * strips `debug` unless `config('app.debug')` — so a raw read would have published the stack
     * trace and SQL that slot is designed to hold, in production, on the error path, which is
     * precisely the payload and precisely the moment this trait exists to protect. It would also
     * have leaked spatie's `_additional` / `_dataContext` internals into every recovered body.
     *
     * A consumer whose projection filters MORE than visibility does should override this and repeat
     * the strip — see {@see ResponseBody::recoveredState()}. Overrides must not call the projection.
     *
     * @return array<array-key, mixed>
     */
    protected function recoveredState(): array
    {
        $state = [];
        foreach ((new \ReflectionObject($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            if (str_starts_with($name, '_') || ! $prop->isInitialized($this)) {
                continue;
            }
            $state[$name] = $prop->getValue($this);
        }

        return $state;
    }

    /**
     * @param  array<array-key, mixed>|Closure(): array<array-key, mixed>  $payload
     */
    protected function jsonResponseThatCannotThrow(array|Closure $payload, int $status): JsonResponse
    {
        if ($payload instanceof Closure) {
            try {
                $payload = $payload();
            } catch (Throwable) {
                // Stage 0 failed: there is no payload to encode at all. Recover the object's own
                // declared state and sanitise it; the message survives, the projection does not.
                $recovered = $this->toJsonSafeValue($this->recoveredState());
                $payload = is_array($recovered) ? $recovered : [];
            }
        }

        try {
            return new JsonResponse($payload, $status);
        } catch (Throwable) {
            // Stage 1 failed. Fall through rather than let the encoder's complaint become the body.
        }

        try {
            $json = json_encode(
                $this->toJsonSafeValue($payload),
                JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
            );

            if (is_string($json)) {
                return new JsonResponse($json, $status, [], 0, true);
            }
        } catch (Throwable) {
            // Stage 2 failed. Fall through to the minimal envelope.
        }

        return new JsonResponse(
            json_encode([
                'success' => false,
                'message' => $this->jsonSafeString(
                    is_string($payload['message'] ?? null) ? $payload['message'] : 'Server error.'
                ),
            ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{"success":false}',
            $status,
            [],
            0,
            true,
        );
    }

    /**
     * A projection of $value that `json_encode` accepts, whatever $value is.
     */
    protected function toJsonSafeValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::SAFE_ENCODE_MAX_DEPTH) {
            return '{depth-limit}';
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }

        if (is_string($value)) {
            return $this->jsonSafeString($value);
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[is_string($key) ? $this->jsonSafeString($key) : $key] = $this->toJsonSafeValue($item, $depth + 1);
            }

            return $out;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        // The reported defect: a pure enum has no default serialization, so name the case.
        if ($value instanceof UnitEnum) {
            return $value::class.'::'.$value->name;
        }

        if ($value instanceof Closure) {
            return '{closure}';
        }

        if (is_resource($value)) {
            return '{resource}';
        }

        if (is_object($value)) {
            // Objects that encode cleanly keep their shape; anything else becomes a name.
            try {
                $encoded = json_encode($value, JSON_THROW_ON_ERROR);
                $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

                return is_array($decoded) ? $this->toJsonSafeValue($decoded, $depth + 1) : $decoded;
            } catch (Throwable) {
                return '{object('.$value::class.')}';
            }
        }

        return '{'.gettype($value).'}';
    }

    private function jsonSafeString(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8')
            ? $value
            : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
