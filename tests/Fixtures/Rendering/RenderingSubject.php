<?php

namespace Splicewire\Beam\Tests\Fixtures\Rendering;

use RuntimeException;
use Splicewire\Beam\Rendering\Subjects\FindOrFailSubjectResolver;

/**
 * A stand-in for the host record a rendering renders. Deliberately NOT an Eloquent model — the engine
 * ships no models and forbids illuminate/database, so the tests prove the controller reaches its subject
 * purely through the duck-typed `findOrFail` the {@see FindOrFailSubjectResolver}
 * expects.
 */
class RenderingSubject
{
    /** @var array<string, self> */
    public static array $records = [];

    /** @var list<string> the eager loads the resolver asked for, so a test can assert query-shape parity */
    public static array $eagerLoaded = [];

    public function __construct(public string $id, public string $title = 'untitled') {}

    public static function seed(string $id, string $title = 'untitled'): self
    {
        return static::$records[$id] = new self($id, $title);
    }

    public static function reset(): void
    {
        static::$records = [];
        static::$eagerLoaded = [];
    }

    /** @param  list<string>  $relations */
    public static function with(array $relations): RenderingSubjectQuery
    {
        static::$eagerLoaded = $relations;

        return new RenderingSubjectQuery;
    }

    public static function findOrFail(string $id): self
    {
        return static::$records[$id] ?? throw new RuntimeException("No fixture subject [{$id}].");
    }
}
