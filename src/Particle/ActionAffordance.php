<?php

namespace Splicewire\Beam\Particle;

use InvalidArgumentException;
use Schemastud\Frame\Registry\ResourceActionDefinition;

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
 */
class ActionAffordance
{
    /** The shorthand string the slot accepts for an all-defaults action. */
    public const Shorthand = 'action';

    /**
     * @param  string|null  $label  the button's words; null ⇒ the op's name, headlined (`reload` ⇒ "Reload")
     * @param  'toast'|'navigate'  $result  `toast` shows the response's message and refreshes the resource
     *                                      (the default); `navigate` opens the resulting record
     * @param  bool  $destructive  the button reads as dangerous and a confirm-only press asks first
     */
    public function __construct(
        public ?string $label = null,
        public string $result = ResourceActionDefinition::ResultToast,
        public bool $destructive = false,
    ) {
        if (! in_array($this->result, [ResourceActionDefinition::ResultToast, ResourceActionDefinition::ResultNavigate], true)) {
            throw new InvalidArgumentException(sprintf(
                'An action affordance presents its result as `%s` or `%s`, not [%s].',
                ResourceActionDefinition::ResultToast,
                ResourceActionDefinition::ResultNavigate,
                $this->result,
            ));
        }
    }
}
