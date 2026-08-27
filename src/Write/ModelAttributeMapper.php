<?php

namespace Splicewire\Beam\Write;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Http\Particle\ParticleController;
use Splicewire\Beam\Particle\ParticleFrameResourceHandler;
use Splicewire\Beam\Surgeon\UndeclaredWriteMapAudit;
use Splicewire\Beam\Write\Contracts\MapsToModelAttributes;

/**
 * The ONE input→column mapper every particle transport rides. Two questions, one answer:
 *
 *  1. does this input declare its own column map ({@see MapsToModelAttributes}, or the duck-typed
 *     `toModelAttributes()` it is replacing)? Then that map IS the answer.
 *  2. otherwise, snake-case the body onto columns and drop the primary key.
 *
 * ## Why it is a collaborator and not four copies
 *
 * It was four. `ParticleController`, {@see ParticleFrameResourceHandler}, and the `DefaultResourceHandler`
 * in each of two hosts each carried a near-identical loop — and they had already diverged in exactly the
 * ways {@see ParticleController}'s own docblock warns about forty lines above its copy (*"two copies of a
 * gate are a gate that will diverge again"*): the `id` skip was spelled three ways, the parameter type
 * three ways (`Request`, `array`, `mixed`), and the controller's `Data` branch returned `toArray()`
 * **unmapped**, so a camelCase Data property reached a snake_case column only by luck.
 *
 * **The signature is `array`-shaped, not `Request`-shaped, deliberately.** The controller's copy took an
 * `Illuminate\Http\Request` and used `->except('id')`; adopting that here would re-couple the write path
 * to HTTP, which is precisely what `ResolvesOperationSubject`'s docblock refuses to do on the read side.
 * A `Request` is still accepted at the door and flattened immediately — the coupling stops at
 * {@see arrayFrom()}.
 *
 * ## The fallback is a temporary, and it is a hand-rolled library feature
 *
 * `columns()` is `#[MapInputName(SnakeCaseMapper::class)]` written by hand. The estate uses
 * {@see MapInputName} 175 times and `MapOutputName` 8 — it maps *in* declaratively and maps *out to
 * columns* imperatively, which is the whole gap in one ratio. The fallback exists because the contract
 * did not; it has no other justification, and it is deleted along with the `method_exists()` branch once
 * {@see UndeclaredWriteMapAudit} reports zero.
 */
class ModelAttributeMapper
{
    /**
     * Map a parsed input to model columns: the input's own declared map, else its duck-typed one, else
     * the snake-cased body.
     *
     * @return array<string, mixed>
     */
    public static function map(mixed $input): array
    {
        if ($input instanceof MapsToModelAttributes) {
            return $input->toModelAttributes();
        }

        // The duck type this interface supersedes. Kept until the audit's count reaches zero — the
        // advisory-then-flip schedule, not a permanent second door.
        if (is_object($input) && method_exists($input, 'toModelAttributes')) {
            /** @var array<string, mixed> */
            return $input->toModelAttributes();
        }

        return self::columns($input);
    }

    /**
     * The declaration-free fallback: snake-case the body onto columns, minus the primary key (a create
     * lets the model mint its own, an update is keyed by the route id).
     *
     * @return array<string, mixed>
     */
    public static function columns(mixed $input): array
    {
        $attributes = [];

        foreach (self::arrayFrom($input) as $key => $value) {
            if ($key === 'id') {
                continue;
            }

            $attributes[Str::snake((string) $key)] = $value;
        }

        return $attributes;
    }

    /**
     * Flatten whatever the transport parsed into a plain body array. This is the only place in the write
     * path that knows what a {@see Request} is.
     *
     * @return array<array-key, mixed>
     */
    protected static function arrayFrom(mixed $input): array
    {
        if ($input instanceof Data) {
            return $input->toArray();
        }

        if ($input instanceof Request) {
            return $input->all();
        }

        return (array) $input;
    }
}
