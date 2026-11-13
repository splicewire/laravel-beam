<?php

namespace Splicewire\Beam\Collections;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why a Collection read or write was refused (localization 21, the laravel-beam sweep; ticket 13's pattern). Each case's
 * value is the stable `code` a client receives beside the localized message; its English is declared on the case,
 * byte-identical to the literal the refusal carried before, and its key is derived from it.
 */
enum CollectionError: string implements CodedError
{
    use IsCodedError;

    #[Message('Unique Collection writes require a registered database schema anchor on the record connection; the schema registry table is absent.')]
    case UniqueNeedsSchemaRegistry = 'unique_needs_schema_registry';

    #[Message('Unique Collection writes require a registered database schema anchor on the record connection; a filesystem-only schema cannot serialize writes.')]
    case UniqueNeedsDatabaseAnchor = 'unique_needs_database_anchor';

    #[Message('This value is already used by another record in the Collection.')]
    case ValueTaken = 'value_taken';

    #[Message('Guest filters must name a Public Read field.')]
    case GuestFilterNotPublic = 'guest_filter_not_public';

    #[Message('Guest sort must name a Public Read field.')]
    case GuestSortNotPublic = 'guest_sort_not_public';

    #[Message('Collection must be an absolute, unversioned schema stem.')]
    case StemInvalid = 'stem_invalid';

    #[Message('The current schema target is not available.')]
    case SchemaTargetUnavailable = 'schema_target_unavailable';

    #[Message('The current schema does not declare a Collection.')]
    case SchemaHasNoCollection = 'schema_has_no_collection';

    #[Message('The payload does not conform to the target schema for record type [:type].')]
    case PayloadRejected = 'payload_rejected';
}
