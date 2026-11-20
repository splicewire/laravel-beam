<?php

namespace Splicewire\Beam\Flows;

/**
 * A package's static list of the flows it ships (browser-journeys 02, R2-Q1). One such class per
 * package; the package's provider names it to {@see FlowCatalog::declare()} inside its registrant
 * scope. No controller attribute and no attribute scan of package source: a declaration is read at
 * runtime, and the one collection-time need — a test's role rows — is served by the static
 * {@see ListsFlows::flow()} call, which autoloads two pure classes and no app.
 *
 * `flows()` is `static` and pure: it builds declarations with `new`, holds only data and
 * class-strings, and is safe to call before Laravel boots (a Pest dataset resolves its rows in a
 * static provider while PHPUnit builds the suite, before `setUp`).
 */
interface DeclaresFlows
{
    /** @return list<FlowDeclaration> */
    public static function flows(): array;
}
