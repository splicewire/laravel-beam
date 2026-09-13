<?php

namespace Splicewire\Beam\Authorization;

use Splicewire\Beam\Doctor\ModelLessReadGateAudit;

/**
 * How a resource with no Eloquent model is read-gated.
 *
 *  - {@see DeclaredAbility} — the declaration's `policy:` names an ability, and the surfaces that read it
 *    ({@see ResourceVisibility}'s class docblock lists them, and the ones that do not) require the actor
 *    to hold it.
 *  - {@see Undeclared} — no ability. The standing posture app ADR-0119 §2 decided ("skip the check; the
 *    API layer still enforces"), served unchanged and COUNTED by {@see ModelLessReadGateAudit}.
 *
 * ## Why there is no third, "deliberately open" state
 *
 * One was drafted — a backing marker meaning "this backing narrows every record to the actor, so it needs
 * no class-level gate" — and cut before it shipped. The only two model-less resources in the estate were
 * read against it and neither qualifies as written: `MembershipSource` narrows through a host-configurable
 * team resolver this package cannot vouch for, and `ReviewInbox::pending()` does not narrow its
 * composition-cell or concept-anchor arms to the actor at all. A marker with no honest implementor would
 * only have turned the audit's warning into a pass that verified nothing. When an owner rules a model-less
 * resource deliberately open, that ruling is what earns the third state — the `ability: false` shape
 * `ParticleOperation` already ships is the precedent to copy.
 */
enum ModelLessReadPosture: string
{
    case DeclaredAbility = 'declared-ability';
    case Undeclared = 'undeclared';
}
