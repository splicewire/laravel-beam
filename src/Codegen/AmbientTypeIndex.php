<?php

namespace Splicewire\Beam\Codegen;

/**
 * The set of fully-qualified type names an emitted ambient `.d.ts` actually declares.
 *
 * ## Why this exists, and why it is not paranoia
 *
 * A derived type is a dangling reference the moment the type it intersects fails to emit — and
 * "fails to emit silently" is the single most-repeated defect on the particle-contribution-seam map.
 * It has bitten three separate mechanisms, always the same way: a HOST-ROOTED default scan cannot see
 * a PACKAGE's discoverable assets. `config('frame.discover_paths')` points at the host's app dir
 * (ticket 07); spatie's `auto_discover_types` defaults to `app_path()` (ticket 10 §A5); the
 * `#[TypeScript]` scan does the same (ticket 10). Every slice {@see ContributedTypesGenerator}
 * references lives in a package — `TenantCommerceData` in beam-commerce, `AuthUserEmbedData` in
 * beam-embed — so on a host that has not widened its scan, the intersection would name types that were
 * never written.
 *
 * Checking the emitted tree turns that from a TypeScript compile error in a UI nobody has opened yet
 * into a generation-time failure naming the missing class.
 *
 * ## The parse is deliberately shallow
 *
 * Only what spatie's `GlobalNamespaceWriter` emits: `namespace X {` blocks (and the `declare namespace
 * A.B.C {` dotted form), `export type Name =` / `export interface Name` declarations, and brace depth.
 * It is an INDEX, not a TypeScript parser — a name it fails to see costs a false failure with a precise
 * message, never a silent pass, which is the direction to be wrong in.
 */
class AmbientTypeIndex
{
    /** @param  list<string>  $types fully-qualified, dot-separated */
    public function __construct(public array $types = []) {}

    /** Build the index from an emitted `.d.ts` body. */
    public static function fromSource(string $source): self
    {
        $types = [];

        /** @var list<string|null> $scopes a namespace name per open block, null for every other block */
        $scopes = [];

        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            $trimmed = trim($line);

            $opened = substr_count($trimmed, '{');
            $closed = substr_count($trimmed, '}');

            if (preg_match('/^(?:declare\s+)?namespace\s+([A-Za-z0-9_.$]+)\s*\{/', $trimmed, $matches) === 1) {
                $scopes[] = $matches[1];
                $opened--;
            } elseif (preg_match('/^export\s+(?:type|interface)\s+([A-Za-z0-9_$]+)\b/', $trimmed, $matches) === 1) {
                $names = array_filter($scopes, fn (?string $scope): bool => $scope !== null);
                $types[] = implode('.', [...$names, $matches[1]]);
            }

            // Every OTHER open block — an object literal, a mapped type — pushes an anonymous scope, so
            // its closing brace pops that rather than the namespace it sits inside.
            for ($i = 0; $i < $opened; $i++) {
                $scopes[] = null;
            }

            for ($i = 0; $i < $closed && $scopes !== []; $i++) {
                array_pop($scopes);
            }
        }

        return new self(array_values(array_unique($types)));
    }

    public function has(string $type): bool
    {
        return in_array($type, $this->types, true);
    }

    /**
     * Which of `$types` this tree does NOT declare.
     *
     * @param  list<string>  $types
     * @return list<string>
     */
    public function missing(array $types): array
    {
        return array_values(array_filter($types, fn (string $type): bool => ! $this->has($type)));
    }
}
