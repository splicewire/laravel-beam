<?php

namespace Splicewire\Beam\Localization\Client;

/** Whether the host's discovered client catalog could be read (localization 16; ruling 02 §3). Never silently empty. */
enum DiscoveryState: string
{
    case Valid = 'valid';
    case Missing = 'missing';
    case Invalid = 'invalid';
}
