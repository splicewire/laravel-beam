<?php

namespace Splicewire\Beam\Flows;

use InvalidArgumentException;

/**
 * Convenience for a {@see DeclaresFlows} class: a static `flow($key)` lookup over its own `flows()`.
 * Pure and boot-free, so a shipped browser test can take its role rows from the declaration at
 * collection time: `->with(AccountFlows::flow('account.security')->roles)`.
 */
trait ListsFlows
{
    public static function flow(string $key): FlowDeclaration
    {
        foreach (static::flows() as $flow) {
            if ($flow->key === $key) {
                return $flow;
            }
        }

        throw new InvalidArgumentException(static::class." declares no flow '{$key}'.");
    }
}
