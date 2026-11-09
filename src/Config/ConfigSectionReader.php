<?php

namespace Splicewire\Beam\Config;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;
use Splicewire\Beam\Data\Config\ConfigEnvData;
use Splicewire\Beam\Data\Config\ConfigKeyData;
use Splicewire\Beam\Data\Config\ConfigLayerData;
use Splicewire\Beam\Data\Config\ConfigSectionCountsData;
use Splicewire\Beam\Data\Config\ConfigSectionData;
use Splicewire\Beam\Data\Config\ConfigSectionFilesData;
use Throwable;

/**
 * Reads one registered Config Section into its `beam-config` row (ADR-0242). This is the ONE read path: the REST
 * and Frame transports reach it through {@see ConfigSectionBacking}, and the MCP read tool calls it directly, so
 * every transport serves the same row.
 *
 * It gathers the tiers and hands them to {@see ConfigLadder}:
 *
 * - **observed**: `config($root)`, the truth every effective value is taken from (so it holds under `config:cache`);
 * - **package**: the registered package file, required in isolation;
 * - **host**: the host file's top-level keys and `env()` bindings, read from its syntax tree by
 *   {@see ConfigFileScanner}, never executed.
 *
 * A host root may live in its own file (`config/app/meters.php` for `app.meters`) or as a key of a parent file
 * (`config/splicewire.php['retrieval']` for `splicewire.retrieval`); the deepest existing file wins.
 *
 * Secrets never leave here: a secret key's value and every layer value are replaced by null, and `set` says
 * whether it has one. Values a JSON wire cannot carry (an object a config file instantiates) are described by
 * class, never serialized.
 */
class ConfigSectionReader
{
    public function __construct(
        protected Repository $config,
        protected ConfigFileScanner $scanner,
        protected ConfigSectionSchema $schemas,
        /** The host's config directory; null reads `config_path()`. */
        protected ?string $configDirectory = null,
    ) {}

    public function read(RegisteredConfigSection $registered): ConfigSectionData
    {
        $section = $registered->section;
        $declaration = $section::declaration();
        $root = $declaration->root;

        $observed = $this->config->get($root);
        $observed = is_array($observed) ? $observed : [];

        $packageFile = $registered->packageFile !== null && is_file($registered->packageFile) ? $registered->packageFile : null;
        $package = $packageFile === null ? null : $this->requireIsolated($packageFile);
        $packageScan = $packageFile === null ? null : $this->scanner->scan($packageFile);

        [$hostFile, $within] = $this->hostFile($root);
        $hostScan = $hostFile === null ? null : $this->scanner->scan($hostFile, $within);

        $resolution = ConfigLadder::fold(
            package: $package,
            hostKeys: $hostScan?->keys,
            observed: $observed,
            packageSource: $this->display($packageFile),
            hostSource: $this->display($hostFile),
            envBound: $packageScan === null ? [] : array_keys($packageScan->bindings),
        );

        $keys = [];

        foreach ($resolution->keys as $pointer => $resolved) {
            $keys[] = $this->key($section, $resolved, $packageScan, $hostScan);
        }

        return new ConfigSectionData(
            id: $root,
            root: $root,
            title: $declaration->title,
            description: $declaration->description,
            owner: $registered->owner,
            files: new ConfigSectionFilesData(package: $this->display($packageFile), host: $this->display($hostFile)),
            schema: $this->schemas->for($section),
            values: $this->redacted($section, $resolution->values),
            keys: $keys,
            shadowedPackageKeys: $resolution->shadowedPackageKeys,
            counts: new ConfigSectionCountsData(
                keys: count($keys),
                overridable: count(array_filter($keys, fn (ConfigKeyData $key): bool => $key->overridable)),
                deploy: count(array_filter($keys, fn (ConfigKeyData $key): bool => $key->effect === ConfigEffect::Deploy)),
                secrets: count(array_filter($keys, fn (ConfigKeyData $key): bool => $key->secret)),
                envBound: count(array_filter($keys, fn (ConfigKeyData $key): bool => $key->env !== null)),
            ),
        );
    }

    /**
     * @param  class-string<ConfigSection>  $section
     */
    protected function key(string $section, ConfigResolvedKey $resolved, ?ConfigFileScan $packageScan, ?ConfigFileScan $hostScan): ConfigKeyData
    {
        $declared = $section::keyDeclaration(JsonPointer::segments($resolved->pointer));
        $secret = $declared->secret;

        $binding = match ($resolved->tier) {
            ConfigTier::Host => $hostScan?->bindings[$resolved->pointer] ?? null,
            ConfigTier::Package => $packageScan?->bindings[$resolved->pointer] ?? null,
            default => null,
        };

        $env = match (true) {
            $binding !== null => new ConfigEnvData($binding->name, $this->envSet($binding->name), $this->display($binding->file), $binding->line),
            $declared->env !== null => new ConfigEnvData($declared->env, $this->envSet($declared->env), null, null),
            default => null,
        };

        return new ConfigKeyData(
            pointer: $resolved->pointer,
            value: $secret ? null : $this->wire($resolved->value),
            tier: $resolved->tier,
            hostShadowsPackage: $resolved->hostShadowsPackage,
            layers: array_map(fn (ConfigLayerValue $layer): ConfigLayerData => new ConfigLayerData(
                tier: $layer->tier,
                value: $secret ? null : $this->wire($layer->value),
                source: $layer->source,
                shadowed: $layer->shadowed,
                removed: $layer->removed,
            ), $resolved->layers),
            env: $env,
            effect: $declared->effect,
            ceiling: $declared->ceiling,
            overridable: $declared->ceiling->isOverridable(),
            secret: $secret,
            set: $resolved->value !== null && $resolved->value !== '',
            merge: $declared->merge,
        );
    }

    /**
     * The deepest existing config file for a root, and the key path inside it.
     *
     * @return array{0: string|null, 1: list<string>}
     */
    protected function hostFile(string $root): array
    {
        $segments = explode('.', $root);

        for ($depth = count($segments); $depth > 0; $depth--) {
            $file = rtrim($this->configDirectory ?? config_path(), '/').'/'.implode('/', array_slice($segments, 0, $depth)).'.php';

            if (is_file($file)) {
                return [$file, array_slice($segments, $depth)];
            }
        }

        return [null, []];
    }

    /** @return array<array-key, mixed>|null */
    protected function requireIsolated(string $file): ?array
    {
        try {
            $values = (static fn (string $path): mixed => require $path)($file);
        } catch (Throwable) {
            return null;
        }

        return is_array($values) ? $values : null;
    }

    protected function envSet(string $name): bool
    {
        return Env::get($name) !== null;
    }

    /**
     * @param  class-string<ConfigSection>  $section
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $path
     * @return array<array-key, mixed>
     */
    protected function redacted(string $section, array $values, array $path = []): array
    {
        foreach ($values as $key => $value) {
            $here = [...$path, (string) $key];

            if ($section::keyDeclaration($here)->secret) {
                $values[$key] = null;
            } elseif (is_array($value)) {
                $values[$key] = $this->redacted($section, $value, $here);
            } else {
                $values[$key] = $this->wire($value);
            }
        }

        return $values;
    }

    /** A value the JSON wire can carry: an object is named by its class, never serialized. */
    protected function wire(mixed $value): mixed
    {
        return match (true) {
            is_object($value) && ! $value instanceof \JsonSerializable => ['$object' => $value::class],
            is_array($value) => array_map(fn (mixed $item): mixed => $this->wire($item), $value),
            default => $value,
        };
    }

    protected function display(?string $file): ?string
    {
        if ($file === null) {
            return null;
        }

        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
