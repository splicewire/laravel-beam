<?php

namespace Splicewire\Beam\Config\Overrides;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Rushing\Versioning\Contracts\VersionStore;
use Rushing\Versioning\Models\Version;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Schemastud\DataSchemas\Overlay\JsonPathMutator;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Splicewire\Beam\Config\JsonPointer;
use Splicewire\Beam\Config\RegisteredConfigSection;
use Splicewire\Beam\Enums\ConfigMerge;
use Splicewire\Beam\Enums\ConfigOverrideOp;
use Splicewire\Beam\Enums\ConfigTier;
use Splicewire\Beam\Facades\Beam;
use Throwable;

/**
 * The operator tier's override store (ADR-0245 decision 2 and §9; config-sections ticket 05): the draft, the two pins,
 * the versions and the revisions of every section, on the `central` connection. It has its own direct publish, for a
 * host without beam-ux; a Release joins in ticket 08.
 *
 * - {@see saveDraft()} applies `set` / `null` / `unset` ops to a section's draft. Each write is refused unless the
 *   key's ceiling admits the operator tier, its pointer is addressable, an `extend` key's entry is new, and the
 *   section's document with the draft applied validates against the section schema through the same
 *   {@see AcceptanceGate} a particle write uses. Each op appends one {@see ConfigOverrideRevision}; the draft is saved
 *   as a new version and HEAD moves to it. A draft changes nothing the runtime applies.
 * - {@see publish()} moves the published pin to HEAD and moves the published-version counter, so every process
 *   re-applies on its next request, job or command.
 * - {@see discardDraft()} puts the draft back to the published version and HEAD with it; nothing published changes.
 * - {@see restore()} rehydrates the draft from a version and saves it as a NEW version: a restore is a draft.
 */
class ConfigOverrideStore
{
    /**
     * The section schema and the acceptance gate are resolved on first VALIDATION, never at construction. The overlay
     * builds this store on every boot ({@see ConfigOverlay::sync()} from the provider's `booted()` hook), and reading the
     * published layers needs neither. Resolving them eagerly built the Schemastud `Generator` chain in every boot,
     * including boots that register only some providers, where `Generator` (an interface) has no binding.
     */
    public function __construct(
        protected ConfigSectionRegistry $sections,
        protected Container $container,
        protected VersionStore $versions,
        protected ConfigOverrideCounter $counter,
    ) {}

    /** What the last {@see publish()} must tell the operator about propagation, or null when there is nothing to tell. */
    protected ?string $propagationNotice = null;

    public function propagationNotice(): ?string
    {
        return $this->propagationNotice;
    }

    /**
     * The fault-mode notice (root ruling (A), row 64e24681): a publish whose token could not be written to the cache
     * reaches other processes within `recheck_seconds` instead of on their next request, job or command.
     */
    public static function cacheWriteFailedNotice(): string
    {
        return sprintf('published; propagation to other processes may take up to %d s (cache write failed)', ConfigOverlay::recheckSeconds());
    }

    protected function schemas(): ConfigSectionSchema
    {
        return $this->container->make(ConfigSectionSchema::class);
    }

    protected function gate(): AcceptanceGate
    {
        return $this->container->make(AcceptanceGate::class);
    }

    /**
     * @param  list<array{pointer: string, op: string|ConfigOverrideOp, value?: mixed}>  $ops
     */
    public function saveDraft(string $root, array $ops, ?string $actor = null): ConfigOverrideScope
    {
        $registered = $this->registered($root);
        $normalized = $this->normalize($registered, $ops, $this->base($root));

        return DB::connection('central')->transaction(function () use ($registered, $root, $normalized, $actor): ConfigOverrideScope {
            $scope = $this->lockedScope($root);
            $draft = $scope->draft ?? [];
            $base = $this->base($root);
            $now = Carbon::now()->toIso8601String();
            $pending = [];

            foreach ($normalized as $i => [$pointer, $op, $value, $secret, $merge, $via]) {
                // A parent op replaces what its earlier set split out: drop the secret children derived from it.
                if ($via === null) {
                    $draft = array_filter($draft, fn (array $entry): bool => ($entry['via'] ?? null) !== $pointer);
                }

                if ($merge === ConfigMerge::Extend && $op === ConfigOverrideOp::Set) {
                    [$exists] = JsonPointer::get($base, $pointer);

                    if ($exists && ! isset($draft[$pointer])) {
                        throw ConfigOverrideRefusal::because("ops.{$i}.pointer", "{$pointer} is an extend key, and its entry already exists in a tier below the operator; an override may add entries, never replace one.");
                    }
                }

                if ($op === ConfigOverrideOp::Unset) {
                    unset($draft[$pointer]);
                } else {
                    $draft[$pointer] = ['op' => $op->value, 'secret' => $secret, 'set' => $op === ConfigOverrideOp::Set, 'setAt' => $now]
                        + ($secret || $op !== ConfigOverrideOp::Set ? [] : ['value' => $value])
                        + ($via === null ? [] : ['via' => $via]);
                }

                $pending[] = [$pointer, $op, $value, $secret];
            }

            $schema = $this->schemas()->for($registered->section);
            $candidate = $this->governed($this->document($base, $draft, $pending), $schema);

            if (! $this->gate()->accepts($candidate, $schema)) {
                throw ConfigOverrideRefusal::because('ops', "The draft for {$root} does not validate against its section schema.");
            }

            $revision = (int) ConfigOverrideRevision::query()->where('section', $root)->max('revision');

            foreach ($pending as [$pointer, $op, $value, $secret]) {
                $row = ConfigOverrideRevision::query()->create([
                    'section' => $root,
                    'pointer' => $pointer,
                    'op' => $op,
                    'value' => $secret || $op !== ConfigOverrideOp::Set ? null : $value,
                    'encrypted_value' => $secret && $op === ConfigOverrideOp::Set ? json_encode($value) : null,
                    'revision' => ++$revision,
                    'created_by' => $actor,
                ]);

                if ($op !== ConfigOverrideOp::Unset) {
                    $draft[$pointer]['revision'] = $row->id;
                }
            }

            $scope->draft = $draft;
            $scope->save();
            $scope->snapshotVersion();

            return $scope->refresh();
        });
    }

    /** Move the published pin to HEAD; a no-op when nothing is pending. */
    public function publish(string $root): ConfigOverrideScope
    {
        $this->registered($root);
        $this->propagationNotice = null;

        $moved = false;

        $scope = DB::connection('central')->transaction(function () use ($root, &$moved): ConfigOverrideScope {
            $scope = $this->lockedScope($root);

            if ($scope->head_version === null) {
                throw ConfigOverrideRefusal::because('root', "{$root} has no saved draft to publish.");
            }

            if ($scope->published_version !== $scope->head_version) {
                $scope->published_version = $scope->head_version;
                $scope->save();
                $moved = true;
            }

            return $scope;
        });

        if ($moved) {
            $this->announce($root);
        }

        return $scope->refresh();
    }

    /**
     * A fingerprint of the published state: every section's published pin, from one query on the scopes table. Equal
     * state gives an equal token; any publish moves a pin, and a restore always makes a NEW version, so a pin never
     * returns to an earlier value and a token is never reused. Compared for equality only.
     */
    public function publishedToken(): string
    {
        if (! Schema::connection('central')->hasTable(Beam::table('config_scopes'))) {
            return 'none';
        }

        $pins = ConfigOverrideScope::query()->whereNotNull('published_version')->orderBy('section')
            ->pluck('published_version', 'section')->all();

        return $pins === [] ? 'none' : sha1(json_encode($pins, JSON_THROW_ON_ERROR));
    }

    /** Put the draft back to the published version (or to nothing, when none is published). */
    public function discardDraft(string $root): ConfigOverrideScope
    {
        $this->registered($root);

        return DB::connection('central')->transaction(function () use ($root): ConfigOverrideScope {
            $scope = $this->lockedScope($root);
            $published = $scope->published_version === null ? null : $this->versions->version($scope, $scope->published_version);
            $scope->restoreVersionSnapshot($published->snapshot ?? ['draft' => []]);
            $scope->head_version = $scope->published_version;
            $scope->save();

            return $scope->refresh();
        });
    }

    /** Rehydrate the draft from a version and save it as a new version: a restore is a draft, never a publish. */
    public function restore(string $root, string $version): ConfigOverrideScope
    {
        $this->registered($root);

        return DB::connection('central')->transaction(function () use ($root, $version): ConfigOverrideScope {
            $scope = $this->lockedScope($root);
            $target = $this->versions->version($scope, $version)
                ?? throw ConfigOverrideRefusal::because('version', "{$root} has no version {$version}.");
            $this->versions->restore($scope, $target->id);
            $scope->refresh()->snapshotVersion("restored from v{$target->version}");

            return $scope->refresh();
        });
    }

    /** @return list<Version> a section's versions, newest first */
    public function versionsOf(string $root): array
    {
        $scope = ConfigOverrideScope::query()->where('section', $root)->first();

        return $scope === null ? [] : array_values($this->versions->versions($scope)->all());
    }

    public function scope(string $root): ?ConfigOverrideScope
    {
        return ConfigOverrideScope::query()->where('section', $root)->first();
    }

    /** The version number a version id names, or null. */
    public function versionNumber(?ConfigOverrideScope $scope, ?string $id): ?int
    {
        return $scope === null || $id === null ? null : $this->versions->version($scope, $id)?->version;
    }

    /**
     * Every section's PUBLISHED layer, as the overlay applies it.
     *
     * @return array<string, array<string, ConfigOverrideEntry>> root => pointer => entry
     */
    public function publishedLayers(): array
    {
        // Before the host has run the shared migration there is nothing published, and that is not a failure: boot,
        // `migrate` and a fresh install read the package and host tiers without a warning.
        if (! Schema::connection('central')->hasTable(Beam::table('config_scopes'))) {
            return [];
        }

        $layers = [];

        foreach (ConfigOverrideScope::query()->whereNotNull('published_version')->get() as $scope) {
            $layers[$scope->section] = $this->publishedLayer($scope);
        }

        return $layers;
    }

    /** @return array<string, ConfigOverrideEntry> pointer => entry */
    public function publishedLayer(ConfigOverrideScope $scope): array
    {
        if ($scope->published_version === null) {
            return [];
        }

        // Through the version store, which reads on the scope's own connection (`central`). A bare Version query
        // would read the DEFAULT connection, which is the tenant's during a tenant request.
        $version = $this->versions->version($scope, $scope->published_version);
        $draft = $version?->snapshot['draft'] ?? [];

        return $this->entries(is_array($draft) ? $draft : [], $version?->version);
    }

    /**
     * @param  array<string, array<string, mixed>>  $draft
     * @return array<string, ConfigOverrideEntry>
     */
    protected function entries(array $draft, ?int $version): array
    {
        $secretIds = array_values(array_filter(array_map(
            fn (array $entry): ?string => ($entry['secret'] ?? false) && ($entry['op'] ?? null) === 'set' ? ($entry['revision'] ?? null) : null,
            $draft,
        )));
        $secrets = $secretIds === [] ? collect() : ConfigOverrideRevision::query()->whereKey($secretIds)->get()->keyBy('id');
        $entries = [];

        foreach ($draft as $pointer => $entry) {
            $op = ConfigOverrideOp::from($entry['op']);
            $secret = (bool) ($entry['secret'] ?? false);
            $value = match (true) {
                $op !== ConfigOverrideOp::Set => null,
                $secret => $secrets->get($entry['revision'] ?? '')?->settingValue(),
                default => $entry['value'] ?? null,
            };
            $entries[(string) $pointer] = new ConfigOverrideEntry((string) $pointer, $op, $value, $secret, (string) ($entry['revision'] ?? ''), $version, $entry['setAt'] ?? null);
        }

        ksort($entries);

        return $entries;
    }

    /**
     * Validate each op's shape and tier ceilings before any write, and split declared secrets out of object values.
     *
     * - **Ceilings at every depth.** An op is checked against the ceiling of every LEAF it touches, not only the
     *   pointer it names: a `set` of an object (or a `null` that clears one) is refused, naming the child pointer,
     *   when it would CHANGE a leaf whose ceiling does not admit the operator tier. A leaf that stays value-equal to
     *   its effective lower-tier value is allowed, so an operator may send a whole object back with its host-only
     *   children untouched.
     * - **Secrets at every depth.** A declared secret leaf inside an object `set` is taken out of the object and
     *   written as its own `set` at its own pointer, through the same encrypted path a direct secret write takes, so
     *   it never reaches a plain override value, a scope draft or a version snapshot. The derived op carries `via`
     *   (the parent pointer), so the parent's next op replaces it.
     *
     * @param  list<array<string, mixed>>  $ops
     * @param  array<array-key, mixed>  $base  the section root without the operator tier
     * @return list<array{0: string, 1: ConfigOverrideOp, 2: mixed, 3: bool, 4: ConfigMerge, 5: ?string}>
     */
    protected function normalize(RegisteredConfigSection $registered, array $ops, array $base): array
    {
        if ($ops === []) {
            throw ConfigOverrideRefusal::because('ops', 'A draft needs at least one op.');
        }

        $normalized = [];

        foreach ($ops as $i => $op) {
            $pointer = $op['pointer'] ?? null;

            if (! is_string($pointer) || ! str_starts_with($pointer, '/') || $pointer === '/') {
                throw ConfigOverrideRefusal::because("ops.{$i}.pointer", 'A pointer is an RFC 6901 pointer below the section root, such as /releases/review_required.');
            }

            try {
                JsonPointer::toJsonPath($pointer);
            } catch (InvalidArgumentException $e) {
                throw ConfigOverrideRefusal::because("ops.{$i}.pointer", $e->getMessage());
            }

            $kind = $op['op'] ?? null;
            $kind = $kind instanceof ConfigOverrideOp ? $kind : (is_string($kind) ? ConfigOverrideOp::tryFrom($kind) : null)
                ?? throw ConfigOverrideRefusal::because("ops.{$i}.op", 'An op is set, null or unset.');

            $declared = $registered->section::keyDeclaration(JsonPointer::segments($pointer));

            if (! $declared->ceiling->admits(ConfigTier::Operator)) {
                throw ConfigOverrideRefusal::because("ops.{$i}.pointer", "{$pointer} has the ceiling {$declared->ceiling->value}; the operator tier may not override it.");
            }

            $value = $kind === ConfigOverrideOp::Set ? ($op['value'] ?? null) : null;

            if ($kind !== ConfigOverrideOp::Unset) {
                $this->guardLeafCeilings($registered, $pointer, $value, $base, $i);
            }

            $derived = [];

            if ($kind === ConfigOverrideOp::Set && is_array($value) && ! $declared->secret) {
                [$value, $derived] = $this->splitSecrets($registered, $pointer, $value);
            }

            $normalized[] = [$pointer, $kind, $value, $declared->secret, $declared->merge, null];

            foreach ($derived as [$child, $leaf, $merge]) {
                $normalized[] = [$child, ConfigOverrideOp::Set, $leaf, true, $merge, $pointer];
            }
        }

        return $normalized;
    }

    /**
     * Refuse an op that would change a leaf below `$pointer` whose ceiling does not admit the operator tier. Compares
     * the leaves of the base object and of the new value (a leaf missing from either side is a change).
     *
     * @param  array<array-key, mixed>  $base
     */
    protected function guardLeafCeilings(RegisteredConfigSection $registered, string $pointer, mixed $value, array $base, int $i): void
    {
        [$exists, $old] = JsonPointer::get($base, $pointer);
        $old = $exists && is_array($old) ? $old : [];
        $new = is_array($value) ? $value : [];

        if ($old === [] && $new === []) {
            return;
        }

        foreach (array_unique([...JsonPointer::leaves($old), ...JsonPointer::leaves($new)]) as $leaf) {
            $declared = $registered->section::keyDeclaration([...JsonPointer::segments($pointer), ...JsonPointer::segments($leaf)]);

            if ($declared->ceiling->admits(ConfigTier::Operator)) {
                continue;
            }

            if (json_encode(JsonPointer::get($old, $leaf)) !== json_encode(JsonPointer::get($new, $leaf))) {
                throw ConfigOverrideRefusal::because("ops.{$i}.value", "{$pointer}{$leaf} has the ceiling {$declared->ceiling->value}; the operator tier may not change it, here inside a write to {$pointer}.");
            }
        }
    }

    /**
     * Take every declared secret leaf out of an object value.
     *
     * @param  array<array-key, mixed>  $value
     * @return array{0: array<array-key, mixed>, 1: list<array{0: string, 1: mixed, 2: ConfigMerge}>} the object without them, and [pointer, value, merge] per secret leaf
     */
    protected function splitSecrets(RegisteredConfigSection $registered, string $pointer, array $value): array
    {
        $derived = [];

        foreach (JsonPointer::leaves($value) as $leaf) {
            $segments = JsonPointer::segments($leaf);
            $declared = $registered->section::keyDeclaration([...JsonPointer::segments($pointer), ...$segments]);

            if (! $declared->secret) {
                continue;
            }

            [, $secret] = JsonPointer::get($value, $leaf);
            $derived[] = [$pointer.$leaf, $secret, $declared->merge];
            $this->forgetAt($value, $segments);
        }

        return [$value, $derived];
    }

    /**
     * @param  array<array-key, mixed>  $doc
     * @param  list<string>  $segments
     */
    protected function forgetAt(array &$doc, array $segments): void
    {
        $key = array_shift($segments);

        if (! array_key_exists($key, $doc)) {
            return;
        }

        if ($segments === []) {
            unset($doc[$key]);
        } elseif (is_array($doc[$key])) {
            $this->forgetAt($doc[$key], $segments);
        }
    }

    /**
     * The section document the draft would produce: the base with every draft entry applied in pointer order.
     *
     * @param  array<array-key, mixed>  $base
     * @param  array<string, array<string, mixed>>  $draft
     * @param  list<array{0: string, 1: ConfigOverrideOp, 2: mixed, 3: bool}>  $pending  this save's ops, values in hand
     * @return array<array-key, mixed>
     */
    protected function document(array $base, array $draft, array $pending): array
    {
        $values = [];

        foreach ($pending as [$pointer, $op, $value]) {
            $values[$pointer] = $value;
        }

        $known = $this->entries(array_diff_key($draft, $values), null);
        $doc = $base;
        $mutator = new JsonPathMutator;
        ksort($draft);

        foreach ($draft as $pointer => $entry) {
            $value = array_key_exists($pointer, $values) ? $values[$pointer] : ($known[$pointer]->value ?? null);
            $mutator->override($doc, JsonPointer::toJsonPath((string) $pointer), $entry['op'] === 'null' ? null : $value);
        }

        return $doc;
    }

    /**
     * The part of a section's document its schema governs. A record-shaped section describes the keys it declares;
     * every other key of the root is undeclared, deploy-only and not overridable (ADR-0245 §Consequences), so it is not
     * validated against a schema that does not describe it. A map-shaped root is governed whole.
     *
     * @param  array<array-key, mixed>  $doc
     * @param  array<string, mixed>  $schema
     * @return array<array-key, mixed>
     */
    protected function governed(array $doc, array $schema): array
    {
        $properties = $schema['properties'] ?? null;

        return is_array($properties) && $properties !== [] ? array_intersect_key($doc, $properties) : $doc;
    }

    /** @return array<array-key, mixed> the section root without the operator tier */
    protected function base(string $root): array
    {
        $base = app(ConfigOverlay::class)->base($root);

        return is_array($base) ? $base : [];
    }

    protected function lockedScope(string $root): ConfigOverrideScope
    {
        $scope = ConfigOverrideScope::query()->where('section', $root)->lockForUpdate()->first();

        return $scope ?? ConfigOverrideScope::query()->create(['section' => $root, 'draft' => []]);
    }

    protected function registered(string $root): RegisteredConfigSection
    {
        return $this->sections->get($root) ?? throw ConfigOverrideRefusal::because('root', "No config section is registered for {$root}.");
    }

    /**
     * After a publish COMMITS: put the new published token in the cache, and apply the publish in THIS process at once,
     * whatever the cache does. A cache write that fails never undoes or hides the publish: other warm processes catch up
     * within `beam.core.config_overrides.recheck_seconds` ({@see ConfigOverlay::sync()}).
     */
    protected function announce(string $root): void
    {
        $overlay = app(ConfigOverlay::class);

        try {
            $this->counter->put($this->publishedToken());
        } catch (Throwable $e) {
            $this->propagationNotice = self::cacheWriteFailedNotice();
            Log::warning($this->propagationNotice, [
                'root' => $root,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        $overlay->recheckNow();
        $overlay->sync();
    }
}
