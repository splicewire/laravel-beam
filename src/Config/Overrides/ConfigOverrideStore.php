<?php

namespace Splicewire\Beam\Config\Overrides;

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
    public function __construct(
        protected ConfigSectionRegistry $sections,
        protected ConfigSectionSchema $schemas,
        protected AcceptanceGate $gate,
        protected VersionStore $versions,
        protected ConfigOverrideCounter $counter,
    ) {}

    /**
     * @param  list<array{pointer: string, op: string|ConfigOverrideOp, value?: mixed}>  $ops
     */
    public function saveDraft(string $root, array $ops, ?string $actor = null): ConfigOverrideScope
    {
        $registered = $this->registered($root);
        $normalized = $this->normalize($registered, $ops);

        return DB::connection('central')->transaction(function () use ($registered, $root, $normalized, $actor): ConfigOverrideScope {
            $scope = $this->lockedScope($root);
            $draft = $scope->draft ?? [];
            $base = $this->base($root);
            $now = Carbon::now()->toIso8601String();
            $pending = [];

            foreach ($normalized as $i => [$pointer, $op, $value, $secret, $merge]) {
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
                        + ($secret || $op !== ConfigOverrideOp::Set ? [] : ['value' => $value]);
                }

                $pending[] = [$pointer, $op, $value, $secret];
            }

            $schema = $this->schemas->for($registered->section);
            $candidate = $this->governed($this->document($base, $draft, $pending), $schema);

            if (! $this->gate->accepts($candidate, $schema)) {
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
            $this->moveCounter($root);
        }

        return $scope->refresh();
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
     * Validate each op's shape and the key's tier ceiling before any write.
     *
     * @param  list<array<string, mixed>>  $ops
     * @return list<array{0: string, 1: ConfigOverrideOp, 2: mixed, 3: bool, 4: ConfigMerge}>
     */
    protected function normalize(RegisteredConfigSection $registered, array $ops): array
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

            $normalized[] = [$pointer, $kind, $kind === ConfigOverrideOp::Set ? ($op['value'] ?? null) : null, $declared->secret, $declared->merge];
        }

        return $normalized;
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

    protected function moveCounter(string $root): void
    {
        try {
            $this->counter->bump();
        } catch (Throwable $e) {
            Log::warning("[beam] published {$root}, but the override counter could not be moved; processes re-read it when the cache is back.", [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
