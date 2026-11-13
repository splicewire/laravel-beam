<?php

namespace Splicewire\Beam\Authorization;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why beam refused access to a read, a write or a hook reach (localization 21, the laravel-beam sweep; ticket 13's
 * pattern; coord-lead ruling 23:18Z on SIGNATURES-21 §2(a)). Each case's value is the stable `code` a client receives
 * beside the localized message. Its English is byte-identical to the literal the refusal carried before.
 *
 * `WriteNotAuthorized` and `DuplicateRejected` name a model class in their message (`:type`, a FQCN). It is passed as a
 * parameter, so today's text is unchanged. Whether a client should see a class name at all is a product question for
 * the owner, not changed here.
 */
enum AccessError: string implements CodedError
{
    use IsCodedError;

    #[Message('Workspace-shared particles require a trusted workspace identity.')]
    case WorkspaceIdentityUntrusted = 'workspace_identity_untrusted';

    #[Message('Workspace-shared particles require an initialized workspace.')]
    case WorkspaceUninitialized = 'workspace_uninitialized';

    #[Message("The subject's population for [:key] cannot be queried.")]
    case HookPopulationUnqueryable = 'hook_population_unqueryable';

    #[Message("The subject is outside [:key]'s read scope.")]
    case HookOutsideReadScope = 'hook_outside_read_scope';

    #[Message('The write gate refused a write to [:type].')]
    case WriteNotAuthorized = 'write_not_authorized';

    #[Message('A capture matching an earlier one was refused for [:type].')]
    case DuplicateRejected = 'duplicate_rejected';
}
