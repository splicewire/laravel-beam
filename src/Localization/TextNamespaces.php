<?php

namespace Splicewire\Beam\Localization;

use Closure;
use Illuminate\Translation\Translator;
use LogicException;

/**
 * The translator namespaces of a host's installed packages, and the owner of a declaration's text (localization
 * ruling 01 §3).
 *
 * - **A package's namespace** is its composer name less the vendor and a leading `laravel-`
 *   (`splicewire/laravel-beam-ux` → `beam-ux`), unless it declares `extra.beam.lang-namespace`, which wins on every
 *   host. So a key never depends on what else a host has installed.
 * - **A shortened name two installed packages share is contested** (in the flagship today, `rushing/codegen` and
 *   `rushing/laravel-codegen` both shorten to `codegen`). A contested namespace is never hinted for either package.
 *   Using one throws where the host is built and is reported in production, as beam already treats a nav-seat
 *   mismatch (`beam.ux.ia.throw`). The declaration then renders its literal. A package that declares its own
 *   namespace leaves the contest.
 * - **The owner of a class** is the package whose PSR-4 root is the longest prefix of the class name
 *   (`Splicewire\Beam\Ux\…` is beam-ux, not beam). A class under the ROOT package is the host's when the root is a
 *   `project`, and a package's when the root is a library under test.
 * - **A hint is registered only for a used namespace**: on first use, or eagerly for a package that ships a `lang/`
 *   directory. The hint points at the package's `lang/` directory even when it ships none, because the framework loads
 *   no lines, not even a host's `lang/vendor/<ns>/` override, for an unhinted namespace. An existing hint for the same
 *   namespace at a different path is a collision too, and is refused, never overwritten.
 */
final class TextNamespaces
{
    /** @var array<string, array{namespace: string, path: string, roots: list<string>, declared: bool}> by package name */
    private array $packages = [];

    /** @var array<string, string> PSR-4 root (trailing `\`) => package name; the host's roots map to '' */
    private array $roots = [];

    /** @var array<string, list<string>> contested namespace => the packages that shorten to it */
    private array $contested = [];

    /** @var array<int, array<string, true>> namespaces already hinted, per translator instance */
    private array $hinted = [];

    /**
     * @param  list<array{name: string, path: string, psr4?: array<string, string|list<string>>, extra?: array<string, mixed>}>  $packages
     * @param  list<string>  $hostRoots  the host application's PSR-4 roots (empty when the root package is a library)
     */
    public function __construct(array $packages, array $hostRoots = [], private Translator|Closure|null $translator = null)
    {
        $byNamespace = [];

        foreach ($packages as $package) {
            $declared = $package['extra']['beam']['lang-namespace'] ?? null;
            $namespace = is_string($declared) && $declared !== '' ? $declared : self::shorten($package['name']);
            $roots = array_map(static fn (string $r): string => rtrim($r, '\\').'\\', array_keys($package['psr4'] ?? []));

            $this->packages[$package['name']] = [
                'namespace' => $namespace,
                'path' => rtrim($package['path'], '/').'/lang',
                'roots' => $roots,
                'declared' => is_string($declared) && $declared !== '',
            ];
            $byNamespace[$namespace][] = $package['name'];

            foreach ($roots as $root) {
                $this->roots[$root] = $package['name'];
            }
        }

        foreach ($hostRoots as $root) {
            $this->roots[rtrim($root, '\\').'\\'] = '';
        }

        foreach ($byNamespace as $namespace => $names) {
            if (count($names) > 1) {
                $this->contested[$namespace] = $names;
            }
        }

        // Longest prefix first, so a nested root (beam-ux inside beam's prefix) is tried before its parent.
        uksort($this->roots, static fn (string $a, string $b): int => strlen($b) <=> strlen($a) ?: strcmp($a, $b));
    }

    /**
     * Built from composer's metadata: `vendor/composer/installed.json` for the packages, and the root `composer.json`,
     * which is the host when its type is `project` and one more package otherwise.
     */
    public static function fromComposer(string $vendorDir, string $rootComposerJson, Translator|Closure|null $translator = null): self
    {
        $installed = self::readJson($vendorDir.'/composer/installed.json');
        $list = $installed['packages'] ?? $installed;
        $packages = [];

        foreach (is_array($list) ? $list : [] as $package) {
            if (! is_array($package) || ! isset($package['name'])) {
                continue;
            }
            $packages[] = [
                'name' => (string) $package['name'],
                'path' => self::installPath($vendorDir, $package),
                'psr4' => (array) ($package['autoload']['psr-4'] ?? []),
                'extra' => (array) ($package['extra'] ?? []),
            ];
        }

        $root = self::readJson($rootComposerJson);
        $rootPsr4 = (array) ($root['autoload']['psr-4'] ?? []);
        $hostRoots = [];

        if (($root['type'] ?? 'library') === 'project' || ! isset($root['name'])) {
            $hostRoots = array_keys($rootPsr4);
        } else {
            $packages[] = [
                'name' => (string) $root['name'],
                'path' => dirname($rootComposerJson),
                'psr4' => $rootPsr4,
                'extra' => (array) ($root['extra'] ?? []),
            ];
        }

        return new self($packages, $hostRoots, $translator);
    }

    /** `splicewire/laravel-beam-ux` → `beam-ux`. */
    public static function shorten(string $composerName): string
    {
        $name = str_contains($composerName, '/') ? substr($composerName, strpos($composerName, '/') + 1) : $composerName;

        return str_starts_with($name, 'laravel-') ? substr($name, 8) : $name;
    }

    /** The owner of a declaring class's text, hinting its namespace on first use. */
    public function ownerOfClass(string $class): TextOwner
    {
        $class = ltrim($class, '\\');

        foreach ($this->roots as $root => $package) {
            if (! str_starts_with($class, $root)) {
                continue;
            }

            return $package === '' ? TextOwner::host() : $this->ownerOfPackage($package);
        }

        return TextOwner::host();
    }

    /** The owner for a package by composer name; refused when its namespace is contested or its hint collides. */
    public function ownerOfPackage(string $package): TextOwner
    {
        $entry = $this->packages[$package] ?? null;
        if ($entry === null) {
            return TextOwner::host();
        }

        $namespace = $entry['namespace'];
        if (isset($this->contested[$namespace])) {
            return $this->refuse($package, sprintf(
                'The translator namespace [%s] is contested by %s; none of them gets derived keys. Declare `extra.beam.lang-namespace` in one package\'s composer.json (localization ruling 01 §3).',
                $namespace, implode(', ', $this->contested[$namespace]),
            ));
        }

        if (! $this->hint($namespace, $entry['path'])) {
            return $this->refuse($package, sprintf(
                'The translator namespace [%s] is already hinted at another path, so [%s] cannot use it; declare `extra.beam.lang-namespace` (localization ruling 01 §3).',
                $namespace, $package,
            ));
        }

        return TextOwner::package($package, $namespace);
    }

    /**
     * Hint every package that ships a `lang/` directory, once the translator exists. Never refuses: a namespace that is
     * contested, or that a package's own provider already hinted (anywhere), is left as it is, so a third-party package
     * cannot fail a host's boot. Refusal happens where a derived key is first formed, in {@see ownerOfPackage()}.
     */
    public function hintShipped(): void
    {
        $translator = $this->translator();
        if ($translator === null) {
            return;
        }
        $hinted = $translator->getLoader()->namespaces();

        foreach ($this->packages as $entry) {
            $namespace = $entry['namespace'];
            if (is_dir($entry['path']) && ! isset($this->contested[$namespace]) && ! isset($hinted[$namespace])) {
                $this->hint($namespace, $entry['path']);
            }
        }
    }

    /** @return array<string, list<string>> */
    public function contested(): array
    {
        return $this->contested;
    }

    public function namespaceOf(string $package): ?string
    {
        return $this->packages[$package]['namespace'] ?? null;
    }

    private function hint(string $namespace, string $path): bool
    {
        $translator = $this->translator();
        if ($translator === null) {
            return true;
        }

        $id = spl_object_id($translator);
        if (isset($this->hinted[$id][$namespace])) {
            return true;
        }

        $existing = $translator->getLoader()->namespaces()[$namespace] ?? null;

        if ($existing !== null && rtrim((string) $existing, '/') !== rtrim($path, '/')) {
            return false;
        }

        if ($existing === null) {
            $translator->addNamespace($namespace, $path);
            if ($translator instanceof BeamTranslator) {
                $translator->forgetLoadedNamespace($namespace);
            }
        }
        $this->hinted[$id][$namespace] = true;

        return true;
    }

    /** Where hints go: the translator given, or the container's current one when built with a resolver. */
    private function translator(): ?Translator
    {
        $translator = $this->translator instanceof Closure ? ($this->translator)() : $this->translator;

        return $translator instanceof Translator ? $translator : null;
    }

    private function refuse(string $package, string $message): TextOwner
    {
        $error = new LogicException($message);
        // TODO(localization 06): `beam.ux.ia.throw` is a stopgap borrowed from the nav-seat policy. Ticket 06's
        // localization config section owns this switch once config-sections 02 lands; move the read there.
        if ((bool) config('beam.ux.ia.throw', ! app()->isProduction())) {
            throw $error;
        }
        report($error);

        return TextOwner::refused($package);
    }

    /** @param  array<string, mixed>  $package */
    private static function installPath(string $vendorDir, array $package): string
    {
        $relative = $package['install-path'] ?? '../'.$package['name'];
        $path = $vendorDir.'/composer/'.$relative;

        return realpath($path) ?: $path;
    }

    /** @return array<string, mixed> */
    private static function readJson(string $file): array
    {
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
