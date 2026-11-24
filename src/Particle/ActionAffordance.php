<?php

namespace Splicewire\Beam\Particle;

use Schemastud\Frame\Registry\ResourceActionResult;

/**
 * How a `#[ParticleOp]` presents itself as a FRAME ACTION — the value of its `affordance:` slot
 * (particle-operation-surface 21, ADR-0223).
 *
 * An operation that declares one is drawn by frame on its resource's framed screen: beside the list's
 * "New" when the op addresses no record, on each row and on the detail page when it addresses `{id}`. Its
 * form is the op's `input:`, and `input: false` makes it a confirm-only button. Who sees it is the op's own
 * `ability:`, asked through the same resolver the mount enforces.
 *
 * `affordance: 'action'` is the shorthand for `new ActionAffordance()` — every default below. Spell the
 * instance when the button needs its own words, a navigate result or a destructive look:
 *
 *     affordance: new ActionAffordance(label: 'Reload credits'),
 *
 * ⚠️ An attribute argument must be a constant expression: `new ActionAffordance(...)` is legal, a static
 * factory is not (the same trap `subject:` and `delivery:` carry).
 *
 * @api
 */
class ActionAffordance
{
    /** The shorthand string the slot accepts for an all-defaults action. */
    public const Shorthand = 'action';

    /**
     * @param  string|null  $label  the button's words; null ⇒ the op's name, headlined (`reload` ⇒ "Reload")
     * @param  ResourceActionResult  $result  how the response is presented
     * @param  bool  $destructive  the button reads as dangerous and a confirm-only press asks first
     */
    public function __construct(
        public ?string $label = null,
        public ResourceActionResult $result = ResourceActionResult::Toast,
        public bool $destructive = false,
    ) {}
}
