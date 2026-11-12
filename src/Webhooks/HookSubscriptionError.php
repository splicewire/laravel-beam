<?php

namespace Splicewire\Beam\Webhooks;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\CodedValidationException;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

/**
 * Why a hook subscription was refused (localization ticket 13's exemplar). Each case's value is the stable `code` a
 * client receives beside the localized message; its English is declared on the case and its key is derived from it.
 * The English is byte-identical to the literals these refusals carried before.
 */
enum HookSubscriptionError: string implements CodedError
{
    use IsCodedError;

    #[Message('An endpoint is required to subscribe.')]
    case EndpointRequired = 'endpoint_required';

    #[Message('Name at least one event to subscribe to. GET hooks/events lists them.')]
    case EventsRequired = 'events_required';

    #[Message('Not in the event catalog: :unknown. Legal names: :legal.')]
    case EventsUnknown = 'events_unknown';

    #[Message('Not in the event catalog: :unknown. Legal names: (this host publishes none).')]
    case EventsUnknownEmptyCatalog = 'events_unknown_empty_catalog';

    #[Message('Supply both :type and :id, or neither.')]
    case SubjectPairIncomplete = 'subject_pair_incomplete';

    #[Message('`:type` is not a model this host knows.')]
    case SubjectTypeUnknown = 'subject_type_unknown';

    #[Message('Not a usable id for a :model.')]
    case SubjectIdUnusable = 'subject_id_unusable';

    #[Message('Not a usable id for a :model — that record is keyed by integer.')]
    case SubjectIdNotInteger = 'subject_id_not_integer';

    #[Message('No such record to subscribe against.')]
    case SubjectNotFound = 'subject_not_found';

    /**
     * The catalog refusal: one sentence when the host publishes events, another when it publishes none, so neither
     * is glued from fragments.
     *
     * @param  list<string>  $unknown
     * @param  list<string>  $legal
     */
    public static function unknownEvents(array $unknown, array $legal): CodedValidationException
    {
        return $legal === []
            ? CodedValidationException::for('events', self::EventsUnknownEmptyCatalog, ['unknown' => implode(', ', $unknown)])
            : CodedValidationException::for('events', self::EventsUnknown, ['unknown' => implode(', ', $unknown), 'legal' => implode(', ', $legal)]);
    }
}
