<?php

namespace Splicewire\Beam\Enums;

/**
 * The Thread discriminator (DIE-04 / ADR-0062): an ordinary `interactive` chat, an
 * `embed_template` (a chat published as a drop-in — its config IS the embed config),
 * or an `embed_session` (a visitor-owned session spawned from a template with a
 * frozen config snapshot).
 *
 * Moved DOWN to beam-core (recohere T02): the ChatBase generic conversation model and
 * the generic embed subsystem both discriminate on it, so the enum lives with the
 * substrate. The former App\Enums\ThreadKind is a bridge alias onto this FQCN.
 */
enum ThreadKind: string
{
    case Interactive = 'interactive';
    case EmbedTemplate = 'embed_template';
    case EmbedSession = 'embed_session';
}
