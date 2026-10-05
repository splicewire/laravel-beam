<?php

namespace Splicewire\Beam\Ia;

use LogicException;

/**
 * A host called a cross-instance surface for a side it does not play (IA-6). It is thrown, not swallowed: the host
 * wrote the call, so registering nothing quietly would hide a wrong route file.
 */
final class SideRefused extends LogicException
{
    /** @param  list<string>  $plays */
    public static function for(Side $side, string $surface, array $plays): self
    {
        return new self("[{$surface}] serves the {$side->value} side, but this host plays [".implode(', ', $plays).'] (beam.core.ia.plays). Remove the call, or declare the side.');
    }
}
