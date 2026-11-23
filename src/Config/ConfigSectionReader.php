<?php

namespace Splicewire\Beam\Config;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\Overrides\ConfigOverrideLock;
use Splicewire\Beam\Config\Overrides\ConfigOverrideStore;
use Splicewire\Beam\Config\Overrides\ConfigTenantOverlay;
use Splicewire\Beam\Config\Overrides\ConfigTierBoundary;
use Splicewire\Beam\Data\Config\ConfigEnvData;
use Splicewire\Beam\Data\Config\ConfigKeyData;
use Splicewire\Beam\Data\Config\ConfigLayerData;
use Splicewire\Beam\Data\Config\ConfigLockData;
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
use Splicewire\Beam\Realm\Contracts\TenancyContext;
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

        // The fold reads the configuration WITHOUT the override tiers (the operator overlay's base) and takes each
        // tier's published layer explicitly, so an override is attributed to its tier and never to a provider
        // (tickets 05 and 07). In the tenant realm the row's draft and pins are the current tenant's, folded above the
        // operator's published layer, whose inherited leaves come from the central effective value.
        $tenantRealm = $this->tenantRealm();
        $observed = app(ConfigOverlay::class)->base($root);
        $observed = is_array($observed) ? $observed : [];
        [$operator, $overrides, $pins] = $this->tierRows(ConfigTier::Operator, $section, $root, $observed);
        $tenant = [];

        if ($tenantRealm) {
            $central = app(ConfigTenantOverlay::class)->central($root);
            [$tenant, $overrides, $pins] = $this->tierRows(ConfigTier::Tenant, $section, $root, is_array($central) ? $central : []);
        }

        $locks = $this->locks($root);
        $effective = app('config')->get($root);
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
            tenant: $tenant,
        );

        $keys = [];
        $rowTier = $tenantRealm ? ConfigTier::Tenant : ConfigTier::Operator;

        foreach ($resolution->keys as $pointer => $resolved) {
            $key = $this->key($section, $resolved, $packageScan, $hostScan, $locks, $rowTier);

            // A key whose declared ceiling excludes the tenant tier, or that its package closed to the tenant tier
            // (config-sections 17), is not offered in the tenant realm (ticket 07); a LOCKED key is, marked locked with
            // the operator's reason.
            if ($tenantRealm && (! $key->ceiling->admits(ConfigTier::Tenant) || in_array(ConfigTier::Tenant, $key->closed, true))) {
                continue;
            }

            $keys[] = $tenantRealm ? $this->forTenant($registered, $key) : $key;
        }

        $title = $this->declarationText($registered, 'title', $declaration->title);
        $description = $this->declarationText($registered, 'description', $declaration->description);
        $schema = $this->schemas->for($section);
        $schema['title'] = $title;

        if (array_key_exists('description', $schema)) {
            $schema['description'] = $description;
        }

        // A tenant row is redacted at the source (root 29b67e82): a workspace admin sees the value, tier and lock (with its
        // reason) of the keys it is OFFERED, and nothing of the rest: never another key's value, the install's env
        // variable names, config file paths or the package and host layers' sources. Every transport serves this one
        // reader, so none can leak them.
        $values = $this->redacted($section, $effective);

        if ($tenantRealm) {
            $values = $this->offeredValues($keys);
            $schema = $this->offeredProperties($this->withoutEnvNames($schema), $keys, $declaration->entries !== null);
        }

        return new ConfigSectionData(
            id: $root,
            root: $root,
            title: $title,
            description: $description,
            owner: $registered->owner,
            files: $tenantRealm
                ? new ConfigSectionFilesData(package: null, host: null)
                : new ConfigSectionFilesData(package: $this->display($packageFile), host: $this->display($hostFile)),
            schema: $schema,
            values: $values,
            keys: $keys,
            shadowedPackageKeys: $tenantRealm
                ? array_values(array_filter($resolution->shadowedPackageKeys, fn (string $pointer): bool => ConfigTierBoundary::admits($registered, JsonPointer::segments($pointer), ConfigTier::Tenant)))
                : $resolution->shadowedPackageKeys,
            counts: new ConfigSectionCountsData(
                keys: count($keys),
                overridable: count(array_filter($keys, fn (ConfigKeyData $key): bool => $key->overridable)),
                deploy: count(array_filter($keys, fn (ConfigKeyData $key): bool => $key->effect === ConfigEffect::Deploy)),
                secrets: count(array_filter($keys, fn (ConfigKeyData $key): bool => $key->secret)),
                envBound: count(array_filter($keys, fn (ConfigKeyData $key): bool => $key->env !== null)),
            ),
            overrides: $overrides,
            pins: $pins,
            tier: $rowTier,
        );
    }

    /** Whether this read runs inside a tenant (the tenant realm's rows), by core's tenancy port. */
    protected function tenantRealm(): bool
    {
        try {
            return app(TenancyContext::class)->isActive();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The operator's locks on a section (central data), pointer-keyed; none when the store cannot be read.
     *
     * @return array<string, ConfigOverrideLock>
     */
    protected function locks(string $root): array
    {
        try {
            return app(ConfigOverrideStore::class)->forTier(ConfigTier::Operator)->locks($root);
        } catch (Throwable) {
            return [];
        }
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
     * One override tier for one section (tickets 05, 07): its PUBLISHED layer as fold layers, its DRAFT as the row's
     * sparse overrides, and the two pins. A store that cannot be read (no tables yet, an unreachable connection) reads
     * as no such tier at all, exactly as before the store existed.
     *
     * @param  class-string<ConfigSection>  $section
     * @param  array<array-key, mixed>  $observed  the root below this tier, which inherited leaves come from
     * @return array{0: array<string, ConfigLayerValue>, 1: list<ConfigOverrideData>, 2: ConfigPinsData}
     */
    protected function tierRows(ConfigTier $tier, string $section, string $root, array $observed = []): array
    {
        $none = [[], [], new ConfigPinsData(draft: null, published: null, draftPending: false)];

        try {
            $store = app(ConfigOverrideStore::class)->forTier($tier);
            $scope = $store->scope($root);

            if ($scope === null) {
                return $none;
            }

            $layer = [];
            $entries = $store->publishedLayer($scope);

            // The tenant tier is folded exactly as the runtime applies it: through the same lock and ceiling cut
            // ({@see ConfigTenantOverlay::restrict()}), so a row cannot report a value the runtime refuses.
            if ($tier === ConfigTier::Tenant && ($registered = app(ConfigSectionRegistry::class)->get($root)) !== null) {
                $entries = ConfigTenantOverlay::restrict($registered, $entries, $this->locks($root), $observed);
            }

            // Each entry carries what it OWNS and what it inherits, so the fold resolves a descendant leaf through it
            // (review-r1, 07; 05's operator fold read exact pointers only).
            foreach ($entries as $pointer => $entry) {
                $layer[$pointer] = new ConfigLayerValue(
                    $tier,
                    $entry->valueOver($observed),
                    "{$tier->value} override · v{$entry->version}",
                    owned: $entry->value,
                    inherit: $entry->inherit,
                );
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
     * @param  array<string, ConfigOverrideLock>  $locks  the section's operator locks
     * @param  ConfigTier  $rowTier  the tier the row's ops write: `overridable` answers for it
     */
    protected function key(string $section, ConfigResolvedKey $resolved, ?ConfigFileScan $packageScan, ?ConfigFileScan $hostScan, array $locks = [], ConfigTier $rowTier = ConfigTier::Operator): ConfigKeyData
    {
        $declared = $section::keyDeclaration(JsonPointer::segments($resolved->pointer));
        $secret = $declared->secret;
        $lock = null;

        foreach ($locks as $candidate) {
            if ($candidate->covers($resolved->pointer)) {
                $lock = new ConfigLockData($candidate->pointer, $candidate->reason, $candidate->created_at?->toIso8601String());

                break;
            }
        }

        // The operator realm keeps 05's answer (any runtime tier may override it), less a key closed to the operator tier
        // its row writes (config-sections 17); the tenant realm answers for the tenant tier, and a lock takes the key away
        // from it. No row sits inside a list item (config-sections 21): a list is a leaf ({@see JsonPointer::leaves()}), so
        // the Config area offers the list as one key, written whole.
        $overridable = $rowTier === ConfigTier::Tenant
            ? $declared->opensTo(ConfigTier::Tenant) && $lock === null
            : $declared->ceiling->isOverridable() && ! $declared->closedTo(ConfigTier::Operator);

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
            overridable: $overridable,
            secret: $secret,
            set: $resolved->value !== null && $resolved->value !== '',
            merge: $declared->merge,
            lock: $lock,
            closed: $declared->closed ?? [],
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

    /** A tenant row's one lower layer: the value the key falls back to without the tenant's own entry. */
    public const INHERITED = 'inherited';

    /**
     * An offered key as a tenant row serves it (root 29b67e82; lead 05:17Z):
     * - its value, and every layer value, without any descendant the tenant is not offered, by the nearest-declaration
     *   walk ({@see ConfigTierBoundary::split()}), so a COMPOUND key (an entry's whole record) carries none of them;
     * - its layers as ONE `inherited` entry (the central effective value it falls back to, with that value's tier as a
     *   tier, or absent) and the tenant's own layer, never the package, host and operator layers one by one: a host
     *   value bound to an env var is an operator's internal;
     * - no env binding, and no host-shadows-package flag.
     */
    protected function forTenant(RegisteredConfigSection $registered, ConfigKeyData $key): ConfigKeyData
    {
        $prune = fn (mixed $value): mixed => is_array($value) && $value !== [] && ! array_is_list($value)
            ? ConfigTierBoundary::split($registered, $key->pointer, $value, ConfigTier::Tenant)[0]
            : $value;
        $below = array_values(array_filter($key->layers, fn (ConfigLayerData $layer): bool => $layer->tier !== ConfigTier::Tenant && ! $layer->shadowed));
        $falls = $below === [] ? null : $below[count($below) - 1];
        $layers = [new ConfigLayerData(
            tier: $falls === null ? ConfigTier::Package : $falls->tier,
            value: $prune($falls?->value),
            source: self::INHERITED,
            shadowed: false,
            removed: $falls === null || $falls->removed,
        )];

        foreach ($key->layers as $layer) {
            if ($layer->tier === ConfigTier::Tenant) {
                $layers[] = new ConfigLayerData($layer->tier, $prune($layer->value), $layer->source, $layer->shadowed, $layer->removed);
            }
        }

        return new ConfigKeyData(
            pointer: $key->pointer,
            value: $prune($key->value),
            tier: $key->tier,
            hostShadowsPackage: false,
            layers: $layers,
            env: null,
            effect: $key->effect,
            ceiling: $key->ceiling,
            overridable: $key->overridable,
            secret: $key->secret,
            set: $key->set,
            merge: $key->merge,
            lock: $key->lock,
            closed: $key->closed,
        );
    }

    /**
     * A tenant row's `values`: only the offered keys' (already pruned and redacted) values, each at its own pointer, so
     * a key the tenant is not offered (its ceiling excludes the tenant tier) leaves no value on the wire.
     *
     * @param  list<ConfigKeyData>  $keys  the offered keys
     * @return array<array-key, mixed>
     */
    protected function offeredValues(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $node = &$values;

            foreach (JsonPointer::segments($key->pointer) as $segment) {
                if (! is_array($node)) {
                    $node = [];
                }

                $node = &$node[$segment];
            }

            $node = $key->value;
            unset($node);
        }

        return $values;
    }

    /**
     * A tenant row's schema: the key class's top-level properties no offered key lies under are dropped (with their
     * `required` entry), so the schema names no such key. For a map-shaped section (`entries:`) those properties live in
     * `additionalProperties`, and a key's property is its SECOND segment (the first is an entry name). Nested records
     * keep their shared `$defs`, which carry types and titles, never values.
     *
     * @param  array<array-key, mixed>  $schema
     * @param  list<ConfigKeyData>  $keys  the offered keys
     * @return array<array-key, mixed>
     */
    protected function offeredProperties(array $schema, array $keys, bool $map = false): array
    {
        $offered = [];

        foreach ($keys as $key) {
            $offered[JsonPointer::segments($key->pointer)[$map ? 1 : 0] ?? ''] = true;
        }

        if ($map) {
            if (is_array($schema['additionalProperties'] ?? null)) {
                $schema['additionalProperties'] = $this->keepProperties($schema['additionalProperties'], $offered);
            }

            return $schema;
        }

        return $this->keepProperties($schema, $offered);
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @param  array<string, true>  $offered
     * @return array<array-key, mixed>
     */
    protected function keepProperties(array $schema, array $offered): array
    {
        if (is_array($schema['properties'] ?? null)) {
            $schema['properties'] = array_filter($schema['properties'], fn (string|int $name): bool => isset($offered[(string) $name]), ARRAY_FILTER_USE_KEY);
        }

        if (is_array($schema['required'] ?? null)) {
            $schema['required'] = array_values(array_filter($schema['required'], fn (mixed $name): bool => isset($offered[(string) $name])));
        }

        return $schema;
    }

    /**
     * The schema without the env variable names its `x-beam-config` keywords declare, at any depth (a tenant row).
     *
     * @param  array<array-key, mixed>  $schema
     * @return array<array-key, mixed>
     */
    protected function withoutEnvNames(array $schema): array
    {
        foreach ($schema as $name => $member) {
            if (! is_array($member)) {
                continue;
            }

            if ($name === ConfigSectionSchema::KEYWORD) {
                unset($member['env']);
                $schema[$name] = $member;

                continue;
            }

            $schema[$name] = $this->withoutEnvNames($member);
        }

        return $schema;
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
