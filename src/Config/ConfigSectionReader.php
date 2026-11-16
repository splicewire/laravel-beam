<?php

namespace Splicewire\Beam\Config;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Data\Config\ConfigEnvData;
use Splicewire\Beam\Data\Config\ConfigKeyData;
use Splicewire\Beam\Data\Config\ConfigLayerData;
use Splicewire\Beam\Data\Config\ConfigOverrideData;
use Splicewire\Beam\Data\Config\ConfigPinsData;
use Splicewire\Beam\Data\Config\ConfigSectionCountsData;
use Splicewire\Beam\Data\Config\ConfigSectionData;
use Splicewire\Beam\Data\Config\ConfigSectionFilesData;
use Splicewire\Beam\Enums\ConfigEffect;
use Splicewire\Beam\Enums\ConfigOverrideOp;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Localization\DeclaredText;
use Splicewire\Beam\Localization\TextNamespaces;
use Throwable;

/**
 * Reads one registered Config Section into its `beam-config` row (ADR-0245). This is the ONE read path: the REST
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
 * The section's own title and description are served resolved ({@see declarationText()}: ADR-0244's `config` kind),
 * both in the row and at the root of the served schema; the committed schema files keep the declared text.
 *
 * Secrets never leave here: a secret key's value and every layer value are replaced by null, and so is a secret
 * nested inside any compound value (a host that replaces a map ancestor makes the fold emit that ancestor, with the
 * package's whole map as a layer, as one leaf), and `set` says
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
        /** Whether config is cached; null asks the application. */
        protected ?bool $configCached = null,
    ) {}

    public function read(RegisteredConfigSection $registered): ConfigSectionData
    {
        $section = $registered->section;
        $declaration = $section::declaration();
        $root = $declaration->root;

        // The fold reads the configuration WITHOUT the operator tier (the overlay's base) and takes the operator's
        // published layer explicitly, so an override is attributed to the operator and never to a provider (ticket 05).
        $observed = app(ConfigOverlay::class)->base($root);
        $observed = is_array($observed) ? $observed : [];
        [$operator, $overrides, $pins] = $this->operatorTier($section, $root, $observed);
        $effective = $this->config->get($root);
        $effective = is_array($effective) ? $effective : $observed;

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
            operator: $operator,
        );

        $keys = [];

        foreach ($resolution->keys as $pointer => $resolved) {
            $keys[] = $this->key($section, $resolved, $packageScan, $hostScan);
        }

        $title = $this->declarationText($registered, 'title', $declaration->title);
        $description = $this->declarationText($registered, 'description', $declaration->description);
        $schema = $this->schemas->for($section);
        $schema['title'] = $title;

        if (array_key_exists('description', $schema)) {
            $schema['description'] = $description;
        }

        return new ConfigSectionData(
            id: $root,
            root: $root,
            title: $title,
            description: $description,
            owner: $registered->owner,
            files: new ConfigSectionFilesData(package: $this->display($packageFile), host: $this->display($hostFile)),
            schema: $schema,
            values: $this->redacted($section, $effective),
            keys: $keys,
            shadowedPackageKeys: $resolution->shadowedPackageKeys,
            counts: new ConfigSectionCountsData(
                keys: count($keys),
                overridable: count(array_filter($keys, fn (ConfigKeyData $key): bool => $key->overridable)),
                deploy: count(array_filter($keys, fn (ConfigKeyData $key): bool => $key->effect === ConfigEffect::Deploy)),
                secrets: count(array_filter($keys, fn (ConfigKeyData $key): bool => $key->secret)),
                envBound: count(array_filter($keys, fn (ConfigKeyData $key): bool => $key->env !== null)),
            ),
            overrides: $overrides,
            pins: $pins,
        );
    }

    /**
     * A section's OWN declaration text (`ConfigRoot` title or description), resolved through an ADR-0244 derived key of
     * the `config` kind (localization 41; config-sections 14): `<ns>::config.<root, its dots as />.<slot>`, owned by the
     * WINNING registration (a package's translator namespace, or no prefix for the host), with the declared English text
     * as the fallback line. A refused owner forms no key, so the declared text is served. The nested Data classes keep the
     * data rule (`BeamSchemaText`); config VALUES never pass through here (localization 27).
     */
    protected function declarationText(RegisteredConfigSection $registered, string $slot, string $declared): string
    {
        $prefix = app(TextNamespaces::class)->ownerOfPackage($registered->owner)->keyPrefix();
        $key = DeclaredText::derive($prefix, 'config', [$registered->root()], $slot);

        return app(DeclaredText::class)->resolve($key, $declared) ?? $declared;
    }

    /**
     * The operator tier for one section (ticket 05): its PUBLISHED layer as fold layers, its DRAFT as the row's sparse
     * overrides, and the two pins. A store that cannot be read (no tables yet, an unreachable connection) reads as no
     * operator tier at all, exactly as before the store existed.
     *
     * @param  class-string<ConfigSection>  $section
     * @param  array<array-key, mixed>  $observed  the root without the operator tier, which inherited leaves come from
     * @return array{0: array<string, ConfigLayerValue>, 1: list<ConfigOverrideData>, 2: ConfigPinsData}
     */
    protected function operatorTier(string $section, string $root, array $observed = []): array
    {
        $none = [[], [], new ConfigPinsData(draft: null, published: null, draftPending: false)];

        try {
            $store = app(ConfigOverrideStore::class);
            $scope = $store->scope($root);

            if ($scope === null) {
                return $none;
            }

            $layer = [];

            foreach ($store->publishedLayer($scope) as $pointer => $entry) {
                $layer[$pointer] = new ConfigLayerValue(ConfigTier::Operator, $entry->valueOver($observed), "operator override · v{$entry->version}");
            }

            $overrides = [];

            foreach ($scope->draftEntries() as $pointer => $entry) {
                $segments = JsonPointer::segments((string) $pointer);
                $secret = (bool) ($entry['secret'] ?? false);
                $overrides[] = new ConfigOverrideData(
                    pointer: (string) $pointer,
                    op: ConfigOverrideOp::from($entry['op']),
                    value: $secret ? null : $this->redactAt($section, $segments, $entry['value'] ?? null),
                    secret: $secret,
                    set: (bool) ($entry['set'] ?? false),
                    setAt: $entry['setAt'] ?? null,
                    revision: (string) ($entry['revision'] ?? ''),
                );
            }

            return [$layer, $overrides, new ConfigPinsData(
                draft: $store->versionNumber($scope, $scope->head_version),
                published: $store->versionNumber($scope, $scope->published_version),
                draftPending: $scope->head_version !== $scope->published_version,
            )];
        } catch (Throwable) {
            return $none;
        }
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

        // Every value leaves through redactAt() at the key's own pointer, so a COMPOUND value (an ancestor the host
        // replaced, which the fold emits as one leaf) is redacted member by member against its descendants'
        // declarations; a non-secret pointer never carries a secret below it.
        $segments = JsonPointer::segments($resolved->pointer);

        return new ConfigKeyData(
            pointer: $resolved->pointer,
            value: $this->redactAt($section, $segments, $resolved->value),
            tier: $resolved->tier,
            hostShadowsPackage: $resolved->hostShadowsPackage,
            layers: array_map(fn (ConfigLayerValue $layer): ConfigLayerData => new ConfigLayerData(
                tier: $layer->tier,
                value: $this->redactAt($section, $segments, $layer->value),
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

    /**
     * Whether the variable is set: true, false, or null for unknown. Under `config:cache` Laravel skips loading
     * `.env`, so a variable only `.env` held is invisible here although the cached config was built with it; an
     * absence then proves nothing ({@see ConfigEnvData}).
     */
    protected function envSet(string $name): ?bool
    {
        if (Env::get($name) !== null) {
            return true;
        }

        return ($this->configCached ?? app()->configurationIsCached()) ? null : false;
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
            $values[$key] = $this->redactAt($section, [...$path, (string) $key], $value);
        }

        return $values;
    }

    /**
     * A value addressed by `$segments`, fit for the wire: null when that pointer is declared secret, and otherwise,
     * for an array, each member redacted at its own pointer, so a secret declared anywhere below survives in no
     * compound value (an ancestor's layer included).
     *
     * @param  class-string<ConfigSection>  $section
     * @param  list<string>  $segments
     */
    protected function redactAt(string $section, array $segments, mixed $value): mixed
    {
        if ($section::keyDeclaration($segments)->secret) {
            return null;
        }

        if (is_array($value)) {
            return $this->redacted($section, $value, $segments);
        }

        return $this->wire($value);
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
