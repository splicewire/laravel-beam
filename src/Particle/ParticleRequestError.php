<?php

namespace Splicewire\Beam\Particle;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why a particle request was refused (localization 21, the laravel-beam sweep; ticket 13's pattern). Each case's value is
 * the stable `code` a client receives beside the localized message; its English is declared on the case, byte-identical
 * to the literal the refusal carried before, and its key is derived from it.
 */
enum ParticleRequestError: string implements CodedError
{
    use IsCodedError;

    #[Message('Unsupported format. This operation delivers :accepted.')]
    case FormatUnsupported = 'format_unsupported';

    #[Message('The `:operation` operation accepts no input.')]
    case NoInputAccepted = 'no_input_accepted';

    #[Message('Unknown input field [:field].')]
    case UnknownInputField = 'unknown_input_field';

    #[Message('The id must match the route record key.')]
    case IdMismatch = 'id_mismatch';

    #[Message('Public projection filters must name a Public Read field.')]
    case PublicFilterNotPublic = 'public_filter_not_public';

    #[Message('Public projection sorts must name a Public Read field.')]
    case PublicSortNotPublic = 'public_sort_not_public';

    #[Message('This field is managed by the server.')]
    case ServerManagedField = 'server_managed_field';

    #[Message('The stored resource schema target is unavailable.')]
    case SchemaTargetUnavailable = 'schema_target_unavailable';

    #[Message('The `:resource` resource accepts no input.')]
    case ResourceAcceptsNoInput = 'resource_accepts_no_input';

    #[Message("No ':resource' record found.")]
    case RecordNotFound = 'record_not_found';

    #[Message("The ':action' action is not supported for the ':resource' frame resource.")]
    case ActionNotSupported = 'action_not_supported';

    #[Message('No filter resource registered for [:key].')]
    case FilterResourceUnknown = 'filter_resource_unknown';

    #[Message('No public intake schema for [:schema].')]
    case IntakeSchemaUnknown = 'intake_schema_unknown';

    #[Message('The submission for [:schema] is invalid.')]
    case IntakeSubmissionInvalid = 'intake_submission_invalid';
}
