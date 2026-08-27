<?php

namespace Splicewire\Beam\Schema;

use Closure;
use ReflectionClass;
use Rushing\Versioning\Contracts\Migrator;
use Rushing\Versioning\Contracts\RecordReconciler;
use Rushing\Versioning\MigrationOutcome;
use Schemastud\DataSchemas\Contracts\SchemaRegistry;
use Schemastud\DataSchemas\Generators\Generator;
use Schemastud\DataSchemas\Keywords;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Schemastud\DataSchemas\Migration\Contracts\LlmMigrator;
use Schemastud\DataSchemas\Migration\MigrationLadder;
use Schemastud\DataSchemas\Migration\Rungs\LlmTryRung;
use Schemastud\DataSchemas\Migration\TransformRegistry;
use Splicewire\Beam\Schema\Contracts\SchemaTargetResolver;

/**
 * The beam-core ADAPTER that binds the `schemastud/laravel-data-schemas`
 * {@see MigrationLadder} to the record-versioning {@see Migrator} port and the
 * {@see RecordReconciler} reconcile-on-read / eager-drain protocol.
 *
 * It lives in beam-core — depending DOWN on BOTH the versioning foundation
 * (`rushing/laravel-versioning`) and the schema foundation
 * (`schemastud/laravel-data-schemas`) — the allowed ADR-0092 direction, so every
 * beam app rides schema-driven migration, not just `splicewire-app`. The concrete
 * engine (`MigrationLadder`, its rungs, the `AcceptanceGate`, the `LlmTryRung`)
 * stays put in data-schemas; nothing is relocated. The target-source *policy* stays
 * host-owned behind the {@see SchemaTargetResolver} port.
 *
 * The `$id`-keyed migration semantics:
 *   - the target is keyed on the schema `$id` and its trailing integer version;
 *   - a stored `$id` newer than current (a downgrade) is a non-event;
 *   - a cross-stem / missing-artifact target is not comparable / failed;
 *   - the recursive expensive-rung detection defers `x-migrate`/`llm` targets to
 *     `pending` on a read (a read never runs an expensive rung);
 *   - the read ladder is built WITHOUT a {@see TransformRegistry} so the custom rung
 *     abstains and a read can never run an expensive transform or reach an LLM.
 *
 * The LLM-try rung is host-bound: its model migrator is built by an injected
 * factory (a consumer supplies its own {@see LlmMigrator} — Composition its
 * Prism-backed one), keeping this adapter free of any product/LLM specifics.
 */
class SchemaLadderMigrator implements Migrator, RecordReconciler
{
    /**
     * @param  Generator  $generator  the host's configured generator CHAIN, not a hardcoded
     *                                `JsonSchemaGenerator`. `data-schemas.generators` is a LIST and the
     *                                dispatch rule lives only inside `ChainedGenerator`, so a
     *                                concrete-typed parameter here made the chain unpassable — which is
     *                                why the provider was still hand-building one. Only `generate()` and
     *                                `canGenerate()` are used; nothing concrete-only was depended on.
     * @param  TransformRegistry|null  $transforms  author-registered custom-transform invocables for the
     *                                              EAGER drain. NEVER consulted on the read path — the read
     *                                              ladder is built without it.
     * @param  SchemaTargetResolver|null  $targetResolver  the target-source authority (system
     *                                                     code-projection vs tenant registry-latest). When
     *                                                     null, the adapter falls back to projecting from the
     *                                                     live class — the historical behavior.
     * @param  (Closure(array<string, mixed>, array<string, mixed>, string): ?LlmMigrator)|null  $llmMigratorFactory
     *                                                                                                                builds the host's model-backed migrator for the armed eager drain, given
     *                                                                                                                ($old schema, $new schema, $recordType). Null (or a null return) leaves the drain
     *                                                                                                                deterministic-rungs-only.
     */
    public function __construct(
        protected SchemaRegistry $registry,
        protected Generator $generator,
        protected ?TransformRegistry $transforms = null,
        protected ?SchemaTargetResolver $targetResolver = null,
        protected ?Closure $llmMigratorFactory = null,
    ) {}

    /**
     * Register an author transform invocable for the eager drain.
     */
    public function withTransforms(TransformRegistry $transforms): self
    {
        $this->transforms = $transforms;

        return $this;
    }

    /**
     * Bind the host's model-backed migrator factory for the armed eager drain.
     *
     * @param  Closure(array<string, mixed>, array<string, mixed>, string): ?LlmMigrator  $factory
     */
    public function withLlmMigratorFactory(Closure $factory): self
    {
        $this->llmMigratorFactory = $factory;

        return $this;
    }

    /**
     * The {@see Migrator} port: migrate a stored payload version-to-version by
     * resolving BOTH the `$from` and `$to` schema artifacts from the frozen registry
     * and running the cheap ladder with the same read-time classification (already
     * current / downgrade non-event / expensive → pending / cheap → migrated or
     * failed). A `$to` with no resolvable artifact is a total no-op (current).
     */
    public function migrate(array $payload, ?string $from, ?string $to): MigrationOutcome
    {
        $new = ($to !== null && $to !== '') ? ($this->registry->get($to) ?? []) : [];

        return $this->classifyRead($payload, $from, $new);
    }

    /**
     * The current absolute, versioned `$id` for a payload record type.
     */
    public function currentId(string $recordType): string
    {
        $id = $this->currentSchema($recordType)['$id'] ?? null;

        return is_string($id) ? $id : '';
    }

    /**
     * The current target schema (with its absolute `$id`) for a record type.
     *
     * @return array<string, mixed>
     */
    public function currentSchema(string $recordType): array
    {
        return $this->targetSchema($recordType, null);
    }

    /**
     * The target schema document for a record type at an optional pinned version.
     * Routed through the {@see SchemaTargetResolver} when one is configured (so a
     * pinned older version / tenant registry-latest resolves); falls back to a
     * live-class projection for "current" otherwise.
     *
     * @return array<string, mixed>
     */
    public function targetSchema(string $recordType, ?int $version): array
    {
        if ($this->targetResolver !== null) {
            return $this->targetResolver->targetFor($recordType, $version);
        }

        // No target resolver (a bare adapter): only the live-class current is
        // projectable. A pinned version has no source here.
        if ($version !== null) {
            return [];
        }

        $class = new ReflectionClass($recordType);

        // GUARDED, because `$generator` is now the host's configured CHAIN (see the type note on the
        // constructor) and `ChainedGenerator::generate()` throws when no member accepts a class.
        //
        // A refusal means "this host has no generator that can describe this record type", and the
        // honest answer to that is NO TARGET — the same `[]` the pinned-version branch above already
        // returns. That is deliberately not the same thing as a target that happens to be empty:
        // `classifyRead()` reads `$new['$id']` as null, `isOlder($storedId, '')` is not comparable and
        // so returns false, and the record is classified `current($storedId)` — payload preserved
        // byte-for-byte, nothing written back, no rung run.
        //
        // NOT MIGRATING is the correct failure here and MIGRATING WRONGLY is not. This is migrate-on-read
        // for BeamParticle: the alternative — letting the throw escape — would take down an ordinary
        // read, and the alternative to THAT — generating with the wrong member — would diff a payload
        // against a schema its own host does not consider authoritative and then WRITE THE RESULT BACK.
        // A record left at its stored version is recoverable the moment the host's chain is corrected;
        // a record migrated against the wrong target is not.
        //
        // The visible cost is that a refused type reads `current` rather than `pending`, so nothing
        // reports the absence from this seam. That is the deliberate trade — this is a runtime read
        // path, not an audit — and the absence is reportable from the doctor tier, where a check whose
        // answer depends on the host belongs.
        return $this->generator->canGenerate($class)
            ? $this->generator->generate($class)
            : [];
    }

    /**
     * {@see RecordReconciler::readAtVersion()} — a pure, NON-mutating view of a
     * prior shape. Resolve the target artifact for `$version`, VALIDATE the stored
     * payload against it, and report the outcome WITHOUT running the ladder and
     * WITHOUT writing back.
     */
    public function readAtVersion(array $payload, string $recordType, int $version): MigrationOutcome
    {
        $target = $this->targetSchema($recordType, $version);
        $targetId = $target['$id'] ?? null;

        // No artifact for the chosen version: there is nothing to validate against.
        if ($target === [] || ! is_string($targetId)) {
            return MigrationOutcome::failedReadOnly($payload, null);
        }

        if ((new AcceptanceGate)->accepts($payload, $target)) {
            // Conforms under the pinned version — a pure view, never written back.
            return MigrationOutcome::current($targetId);
        }

        // Does not conform under the pinned version — surfaced as failed, original
        // preserved, NEVER written back (read-at-version is a pure view).
        return MigrationOutcome::failedReadOnly($payload, $targetId);
    }

    /**
     * {@see RecordReconciler::reconcile()} — the read path. Resolve the target from
     * the record type (implicit current, or the pinned `$targetVersion`) and run the
     * cheap-only classification.
     */
    public function reconcile(array $payload, ?string $storedId, string $recordType, ?int $targetVersion = null): MigrationOutcome
    {
        $new = $this->targetSchema($recordType, $targetVersion);

        return $this->classifyRead($payload, $storedId, $new);
    }

    /**
     * {@see RecordReconciler::reconcileEager()} — the eager drain entry. Runs the
     * FULL ladder (structural + declared-mapping + custom-transform) to complete a
     * `pending` record the read path deferred. `$allowLlm` arms the host-bound
     * model rung via the injected factory; the rung still self-gates on the target's
     * `x-migrate: llm` opt-in and rides the same acceptance gate, so a non-conforming
     * candidate is quarantined. With `$allowLlm` false the ladder is deterministic
     * rungs only.
     */
    public function reconcileEager(array $payload, ?string $storedId, string $recordType, bool $allowLlm = false, ?int $targetVersion = null): MigrationOutcome
    {
        $new = $this->targetSchema($recordType, $targetVersion);
        $currentId = $new['$id'] ?? null;

        if ($storedId === null || $storedId === '' || $storedId === $currentId) {
            return MigrationOutcome::current(is_string($currentId) ? $currentId : null);
        }

        if (! $this->isOlder($storedId, (string) $currentId)) {
            return MigrationOutcome::current($storedId);
        }

        $old = $this->registry->get($storedId);
        if ($old === null) {
            return MigrationOutcome::failed($payload, $storedId);
        }

        // The FULL ladder: structural + declared + custom-transform (backed by the
        // configured registry). The LLM-try rung is appended only when armed AND a
        // host migrator factory produces one.
        $ladder = MigrationLadder::default($this->transforms);

        if ($allowLlm && $this->llmMigratorFactory !== null) {
            $migrator = ($this->llmMigratorFactory)($old, $new, $recordType);
            if ($migrator !== null) {
                $ladder = $ladder->withRungs((new LlmTryRung)->withMigrator($migrator));
            }
        }

        $result = $ladder->migrate($payload, $old, $new);

        if ($result->wasMigrated()) {
            /** @var array<string, mixed> $migrated */
            $migrated = $result->migrated;

            return MigrationOutcome::migrated($migrated, (string) $currentId);
        }

        return MigrationOutcome::failed($result->original, $storedId);
    }

    /**
     * The shared read-time classification against an already-resolved target schema
     * `$new`: already current / downgrade non-event / missing-artifact failed /
     * expensive-rung pending / cheap-migrated or cheap-failed. The read ladder is the
     * DEFAULT (structural + declared-mapping, custom rung abstains) so it can never
     * reach an expensive transform or an LLM.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $new
     */
    protected function classifyRead(array $payload, ?string $storedId, array $new): MigrationOutcome
    {
        $currentId = $new['$id'] ?? null;

        // No stored version, or already at the current version: nothing to do.
        if ($storedId === null || $storedId === '' || $storedId === $currentId) {
            return MigrationOutcome::current(is_string($currentId) ? $currentId : null);
        }

        // A stored `$id` NEWER than current (a downgrade) is never auto-migrated;
        // treat it as a non-event so we never mangle a forward-versioned record.
        if (! $this->isOlder($storedId, (string) $currentId)) {
            return MigrationOutcome::current($storedId);
        }

        // The OLD schema must be resolvable from the frozen registry to diff
        // against. A missing artifact cannot be migrated deterministically.
        $old = $this->registry->get($storedId);
        if ($old === null) {
            return MigrationOutcome::failed($payload, $storedId);
        }

        // Expensive-rung gate: if the current schema pins a custom transform / LLM
        // migration, the upgrade is NOT cheap. A read must not run it — the record
        // reads `pending` for the async drain.
        if ($this->requiresExpensiveRung($new)) {
            return MigrationOutcome::pending($payload, $storedId);
        }

        // Cheap rungs only: the DEFAULT ladder is structural + declared-mapping (+ a
        // custom rung that abstains with no registry) and explicitly EXCLUDES the
        // LLM-try rung. So resolving it can never reach an LLM.
        $result = MigrationLadder::default()->migrate($payload, $old, $new);

        if ($result->wasMigrated()) {
            /** @var array<string, mixed> $migrated */
            $migrated = $result->migrated;

            return MigrationOutcome::migrated($migrated, (string) $currentId);
        }

        // The ladder's null floor: unmigratable by cheap rungs, original preserved.
        return MigrationOutcome::failed($result->original, $storedId);
    }

    /**
     * Whether the target schema declares a rung the read path must not run — an
     * `x-migrate` pin (a custom transform or the `'llm'` opt-in) ANYWHERE in the
     * document, including nested bundled resources under `$defs`. Pure
     * structural/declared (`x-migrate-from`) migrations are cheap.
     *
     * The recursion is what makes the composite atomic: if any nested node needs an
     * expensive rung, the WHOLE instance reads `pending` rather than half-migrating
     * the cheap branches.
     */
    public function requiresExpensiveRung(mixed $node): bool
    {
        if (! is_array($node)) {
            return false;
        }

        foreach ($node as $key => $value) {
            if ($key === Keywords::Migrate && is_string($value) && $value !== '') {
                return true;
            }
            if (is_array($value) && $this->requiresExpensiveRung($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compare two absolute versioned `$id`s of the SAME schema name by their trailing
     * integer version. Returns true when `$candidate` is strictly older than
     * `$current` (and they share the same `<name>` stem).
     */
    public function isOlder(string $candidate, string $current): bool
    {
        $cand = SchemaId::from($candidate);
        $cur = SchemaId::from($current);

        // Different schema names are not comparable — never migrate across them.
        if (! $cand->isComparableTo($cur)) {
            return false;
        }

        return ($cand->version() ?? 0) < ($cur->version() ?? 0);
    }
}
