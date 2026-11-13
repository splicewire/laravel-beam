<?php

namespace Splicewire\Beam\Localization\Catalog;

use Splicewire\Beam\Localization\TextNamespaces;

/**
 * Every line in the PHP lang files a host can load (localization 12): the host's `lang/<locale>/…` (host keys),
 * `lang/vendor/<ns>/<locale>/…` (a host's override of a package), and each family package's own `lang/<locale>/…`. A
 * file directly under the locale is the BASE; one a directory deeper is that directory's REALM RUNG (ruling 05).
 * JSON (sentence-keyed) files are not read: the family does not use them (SPEC).
 */
final class LangFileLines
{
    public function __construct(
        private TextNamespaces $namespaces,
        private string $hostLangPath,
    ) {}

    /**
     * @return list<array{key: string, locale: string, realm: ?string, group: string, file: string}>
     */
    public function all(): array
    {
        $roots = [['', rtrim($this->hostLangPath, '/')]];
        foreach (glob(rtrim($this->hostLangPath, '/').'/vendor/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $roots[] = [basename($dir).'::', $dir];
        }
        foreach ($this->namespaces->familyPackages() as $package) {
            $roots[] = [$package['namespace'].'::', $package['lang']];
        }

        $lines = [];
        foreach ($roots as [$prefix, $root]) {
            foreach (glob($root.'/*', GLOB_ONLYDIR) ?: [] as $localeDir) {
                $locale = basename($localeDir);
                if ($locale === 'vendor') {
                    continue;
                }
                foreach ([null => $localeDir, ...self::realmDirs($localeDir)] as $realm => $dir) {
                    foreach (glob($dir.'/*.php') ?: [] as $file) {
                        $group = basename($file, '.php');
                        $content = @include $file;
                        foreach (KeyCatalog::flatten(is_array($content) ? $content : []) as $item => $text) {
                            $lines[] = [
                                'key' => $prefix.$group.'.'.$item,
                                'locale' => $locale,
                                'realm' => $realm === '' ? null : $realm,
                                'group' => $group,
                                'file' => $file,
                            ];
                        }
                    }
                }
            }
        }

        return $lines;
    }

    /** @return array<string, string> realm => directory */
    private static function realmDirs(string $localeDir): array
    {
        $dirs = [];
        foreach (glob($localeDir.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $dirs[basename($dir)] = $dir;
        }

        return $dirs;
    }
}
