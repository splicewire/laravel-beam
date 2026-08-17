<?php

namespace Splicewire\Beam\Rendering\Subjects;

/**
 * How the renderings controller turns a route `{id}` into the object a rendering renders.
 *
 * This is a port on purpose. The composition engine is persistence-agnostic by topology rule (ADR-0168:
 * an `*-engine` ships ports and pure logic, and its composer guard forbids `illuminate/database`), so
 * the HTTP tier must not reach for Eloquent. The default implementation duck-types the `findOrFail` /
 * `with` pair a host model already has; a host on anything else binds its own.
 */
interface ResolvesRenderingSubject
{
    /**
     * @param  class-string  $subject  the class-string the macro was called with
     * @param  list<string>  $with  relations to eager-load, if the store understands them
     */
    public function resolve(string $subject, string $id, array $with = []): object;
}
