<?php

namespace Splicewire\Beam\Write\Contracts;

use Spatie\LaravelData\Optional;
use Splicewire\Beam\Particle\Attributes\ParticleResource as ParticleResourceAttribute;
use Splicewire\Beam\Surgeon\UndeclaredWriteMapAudit;
use Splicewire\Beam\Write\ModelAttributeMapper;
use Splicewire\Beam\Write\ParticleWriter;

/**
 * A write DTO that knows its own **column map** — the input→column projection every particle transport
 * asks a declared `input:`/`editData:` class for before handing a payload to {@see ParticleWriter}.
 *
 * ## Why this interface exists
 *
 * The map was a **duck type** for the whole life of the write surface: every transport resolved it with
 * `method_exists($input, 'toModelAttributes')`. 25 classes implemented it estate-wide, nothing declared
 * it, nothing checked its signature, and the only way to find an implementor was `grep`. The
 * {@see ParticleResourceAttribute} docblock described the obligation in prose
 * (*"input Data DTO (`toModelAttributes()` write map)"*) and enforced it nowhere. This interface is that
 * prose, made declarable.
 *
 * ## ⚠️ The three-state rule — this is the load-bearing half
 *
 * A write DTO's field carries **three** states, not two, and the difference between the second and the
 * third is the whole reason a hand-written map exists at all:
 *
 * | the caller | the property holds | `toModelAttributes()` must |
 * |---|---|---|
 * | did not mention the field | {@see Optional} | **omit the key** — the column is left untouched |
 * | sent the field as `null` | `null` | **emit `key => null`** — the column is CLEARED |
 * | sent a value | the value | emit `key => value` |
 *
 * So the gate on each property is `! $this->field instanceof Optional`, **never** `!== null` — the
 * latter collapses states one and two into "absent" and makes the DTO structurally unable to null a
 * column. 17 of the original 25 implementations were written that way (`particle-write-surface` 01).
 *
 * A declaration reaches the third state only if the property's type admits `Optional` **and the promoted
 * constructor parameter carries no default**: `Spatie\LaravelData\DataPipes\DefaultValuesDataPipe` checks
 * `hasDefaultValue` BEFORE `type->isOptional`, so the estate's old idiom
 * `public string|Optional|null $name = null` can never produce an `Optional` and is two-state no matter
 * what the union says.
 *
 * ⚠️ **Per-field judgement, not a sweep.** A field whose "clear" is meaningless — an immutable id, a
 * required name, a `NOT NULL` column — is legitimately two-state and should stay on the `!== null` path.
 * Converting every property is a much larger semantic change than converting the ones where clearing is
 * a real operation.
 *
 * ## ⚠️ A conversion is not done until every READER is three-state aware
 *
 * Learned at cost on 2026-08-27 (`splicewire/laravel-beam@4f2021e`). Once a field can be `Optional`,
 * **every reader of that field** is a site that can silently collapse the third state:
 *
 * - `$input->field ?? $fallback` — `??` cannot distinguish absent from an explicit null, so a
 *   re-validation keyed on "did the caller change this?" reads a deliberate clear as *not supplied*,
 *   skips its check, and `toModelAttributes()` writes the null anyway. That is a **skipped
 *   authorization check**, not a cosmetic bug: it is exactly how `HookSubscriptionReach::vetWrite()`
 *   stopped re-vetting a repointed webhook subject.
 * - `!== null`, `?:`, `(string)` — same collapse, and the cast is worse: it throws
 *   *"Object of class Spatie\LaravelData\Optional could not be converted to string"* at runtime.
 *
 * So before converting a field, grep every reader of it. A reader that must handle all three states
 * tests `instanceof Optional` first and only then compares. A reader on a CREATE path may legitimately
 * flatten absent and null together — on a create there is nothing to leave alone — but say so.
 *
 * And never build the map with `get_object_vars()`, which leaks `Optional` sentinels straight onto the
 * write.
 *
 * ## Migration posture — declared, then required
 *
 * Implementing this interface is **not yet mandatory**. The transports still fall back to
 * `method_exists()` ({@see ModelAttributeMapper::map()}), on the advisory-then-flip schedule
 * `ParticleOperation`'s `input:`/`ability:` nulls already ride:
 * {@see UndeclaredWriteMapAudit} reports every `input:`/`editData:` class that
 * declares neither the interface nor the method, and the duck-typed branch is deleted — along with the
 * snake-case fallback — once that count reaches zero.
 */
interface MapsToModelAttributes
{
    /**
     * The model columns this input writes, keyed by column name.
     *
     * An **omitted key** means "leave that column alone"; a key present with `null` means "clear that
     * column". Never include the primary key — a create lets the model mint its own, an update is keyed
     * by the route id.
     *
     * @return array<string, mixed>
     */
    public function toModelAttributes(): array;
}
