<?php

namespace Splicewire\Beam\Codegen;

use Illuminate\Support\Str;
use Splicewire\Beam\Surgeon\SdkNameConventionAudit;

/**
 * The CONVENTION-FIRST naming rules for the `Splicewire\Client\*` SDK, lifted out of
 * {@see SplicewireClientGenerator} so the same rules drive BOTH channels of the client-sdk-regen pivot:
 *  - the GENERATOR, which emits fresh request classes at their convention name;
 *  - the {@see SdkNameConventionAudit}, which measures the EXISTING (hand-picked)
 *    SDK class names against the convention and nominates surgeon renames where they diverge.
 *
 * With the pivot the convention IS the standard — there is no per-route `class` name-override any more.
 * Both consumers name off exactly this one place, so a name can never drift between "what the generator
 * would emit" and "what the audit expects".
 *
 * The `op` shape is the serialized CodegenModel operation (or the audit's reconstruction of it): at least
 * `method` (the HTTP verb), `path` (the route uri), and `meta['tags'][0]` (the `@group` domain tag).
 */
class SdkNaming
{
    /**
     * The domain (namespace segment) for an op — the studly-cased `@group` tag (`meta['tags'][0]`), or
     * null when the op is untagged / out of scope.
     *
     * @param  array<string, mixed>  $op
     */
    public function domainFor(array $op): ?string
    {
        $tag = $op['meta']['tags'][0] ?? null;

        return is_string($tag) && $tag !== '' ? Str::studly($tag) : null;
    }

    /**
     * The convention request-class short-name derived from path + verb:
     *  - a trailing ACTION segment — a non-`{param}` segment that names an operation on an item
     *    (`…/compositions/{id}/render`, `…/ideas/{id}/generate-composition`): a multi-word (hyphenated)
     *    action is self-describing and names itself studly (`GenerateComposition`); a single-word action
     *    gets the addressed resource appended (`RenderComposition`, `PublishComposition`);
     *  - otherwise `<Prefix><SingularNoun>` where the prefix is the verb (`POST` → `Create`,
     *    item `GET` → `Get`, collection `GET` → `List`, `PUT`/`PATCH` → `Update`, `DELETE` → `Delete`)
     *    and the noun is the studly singular of the addressed resource segment (the segment named by a
     *    trailing `{id}` for an item, else the last segment).
     *
     * @param  array<string, mixed>  $op
     */
    public function classNameFor(array $op): string
    {
        $segments = array_values(array_filter(
            explode('/', trim((string) $op['path'], '/')),
            fn ($s) => $s !== '' && $s !== 'api' && ! preg_match('/^v\d+$/', $s),
        ));
        $isParam = fn (string $s): bool => str_starts_with($s, '{');
        $isPlural = fn (string $s): bool => Str::singular($s) !== $s;
        $last = end($segments) ?: 'resource';
        $prevIsParam = count($segments) >= 2 && $isParam($segments[count($segments) - 2]);

        // A trailing ACTION segment names an operation on an item — it comes right after a `{param}`
        // (`…/{id}/render`, `…/{id}/generate-composition`). A multi-word (hyphenated) action is
        // self-describing (`GenerateComposition`); a SINGULAR word gets the resource appended
        // (`RenderComposition`). A PLURAL segment after `{param}` is a sub-resource collection
        // (`…/{id}/cells` → `ListCell`), handled below. A hyphenated segment that is NOT after a
        // `{param}` is a hyphenated RESOURCE name (`context-scopes`, `thread-messages`), not an action.
        if (! $isParam($last) && $prevIsParam && ! $isPlural($last)) {
            if (str_contains($last, '-')) {
                return Str::studly($last);
            }
            $resource = $segments[count($segments) - 3] ?? $segments[count($segments) - 2];

            return Str::studly($last).$this->noun($resource);
        }

        $verb = strtoupper((string) $op['method']);
        $isItem = $isParam($last);
        $resource = $isItem ? ($segments[count($segments) - 2] ?? $last) : $last;

        $prefix = match (true) {
            $verb === 'POST' && $isItem => 'Update',   // POST to an ITEM is an update (this estate posts updates)
            $verb === 'POST' => 'Create',              // POST to a COLLECTION creates
            $verb === 'GET' && $isItem => 'Get',
            $verb === 'GET' => 'List',
            $verb === 'PUT', $verb === 'PATCH' => 'Update',
            $verb === 'DELETE' => 'Delete',
            default => Str::studly(strtolower($verb)),
        };

        return $prefix.$this->noun($resource);
    }

    /** The studly singular of a resource segment (path braces stripped). */
    private function noun(string $segment): string
    {
        return Str::studly(Str::singular(str_replace(['{', '}'], '', $segment)));
    }
}
