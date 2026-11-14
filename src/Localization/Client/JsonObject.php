<?php

namespace Splicewire\Beam\Localization\Client;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use LogicException;
use Traversable;

/**
 * A JSON object as {@see Jcs::parse()} admitted it: its members in document order, read-only. It is distinct from a PHP
 * list, so `{}` stays an object and a key like "0" never turns a map into an array.
 *
 * @implements ArrayAccess<string, mixed>
 * @implements IteratorAggregate<string, mixed>
 */
final class JsonObject implements ArrayAccess, Countable, IteratorAggregate
{
    /** @param  array<array-key, mixed>  $members */
    public function __construct(private readonly array $members = []) {}

    /** @return array<array-key, mixed> the members (PHP may type a numeric-string key as int; JSON keys are strings) */
    public function members(): array
    {
        return $this->members;
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->members);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->members[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('An admitted JSON object is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('An admitted JSON object is read-only.');
    }

    public function count(): int
    {
        return count($this->members);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->members);
    }
}
