<?php

namespace Splicewire\Beam\Tests\Fixtures\ConfigLabelGuard\Elsewhere;

/** Deliberately unlabelled (no ProvidesEnumLabel), and outside the config namespaces. */
enum ProbeScope: string
{
    case Narrow = 'narrow';
    case Wide = 'wide';
}
