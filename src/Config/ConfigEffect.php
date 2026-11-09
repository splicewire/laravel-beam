<?php

namespace Splicewire\Beam\Config;

/**
 * When a change to a key takes effect (ADR-0245). `Deploy` is the default for every key: a key is only ever
 * declared `Live` or `Reload` deliberately, because promising a live change that boot already read is the lie
 * the boot-read audit exists to catch.
 */
enum ConfigEffect: string
{
    /** The next request, job or command reads the new value. */
    case Live = 'live';

    /** Long-lived workers read it once; it applies after they reload. */
    case Reload = 'reload';

    /** Only a deploy (a host file or env change) changes it. */
    case Deploy = 'deploy';
}
