<?php

namespace Splicewire\Beam\Localization\Client;

use Illuminate\Translation\FileLoader;
use Splicewire\Beam\Localization\TextNamespaces;

/**
 * Admits the discovered client namespaces under the ONE collision policy (localization 16; ruling 02 §3; ruling 01 §3).
 *
 * - A catalog that SHARES a sibling's namespace must name, in `sharesWith`, the exact composer package that owns that
 *   namespace on the PHP side.
 * - A catalog with no `sharesWith` must not claim a namespace a PHP family package owns, or any existing hint.
 *   Coincidence is a collision, never a merge.
 * - A genuinely new JS-only namespace gets an EMPTY beam-owned hint, so a host's `lang/vendor/<namespace>/` override
 *   reaches it. An existing hint is never overwritten.
 *
 * A refused namespace is reported with its reason, and its keys stay out of the client population and catalogs.
 */
final class ClientNamespaces
{
    public function __construct(private TextNamespaces $namespaces) {}

    /**
     * @return array<string, string> refused namespace => why
     */
    public function admit(DiscoveredCatalog $catalog): array
    {
        $refused = [];
        $loader = app('translator')->getLoader();
        $hints = $loader instanceof FileLoader ? $loader->namespaces() : [];
        foreach ($catalog->packages as $package) {
            $namespace = $package['namespace'];
            $phpOwners = $this->namespaces->familyOwnersOf($namespace);
            if ($package['sharesWith'] !== null) {
                if ($this->namespaces->namespaceOf($package['sharesWith']) !== $namespace) {
                    $refused[$namespace] = "{$package['name']} shares [{$namespace}] with {$package['sharesWith']}, which does not own it on the PHP side.";
                }

                continue;
            }
            if ($phpOwners !== []) {
                $refused[$namespace] = "{$package['name']} claims [{$namespace}], owned by ".implode(', ', $phpOwners).', without declaring sharesWith.';

                continue;
            }
            if (isset($hints[$namespace]) && rtrim((string) $hints[$namespace], '/') !== rtrim(self::emptyHint($namespace), '/')) {
                $refused[$namespace] = "{$package['name']} claims [{$namespace}], already hinted at {$hints[$namespace]}: a collision, never a merge.";

                continue;
            }
            if (! isset($hints[$namespace])) {
                app('translator')->addNamespace($namespace, self::emptyHint($namespace));
            }
        }
        ksort($refused);

        return $refused;
    }

    /** A beam-owned path holding no lang files: hinting it makes `lang/vendor/<namespace>/` reachable without inventing English. */
    public static function emptyHint(string $namespace): string
    {
        return dirname(__DIR__, 3).'/lang-client/'.$namespace;
    }
}
