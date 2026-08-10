<?php

namespace Splicewire\Beam\Surgeon;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Surgeon\Operation\FixableFinding;
use Rushing\Surgeon\Operation\OperationSuggestion;
use Rushing\Surgeon\Operation\SuggestsOperations;
use Throwable;

/**
 * SDK-hook call-site migration audit (client-sdk-codegen #05 rollout, grilling session 2026-08-06;
 * {@see SdkEndpointDriftAudit} sibling). A domain's `api.ts` sometimes still
 * hand-writes a `useX` react-query wrapper for a route the codegen pipeline has since started emitting a
 * generated hook for (same name, same route). This audit finds those and nominates retiring them.
 *
 * **JS-only scope** (decision 2): this only migrates call sites where a generated hook ALREADY exists —
 * growing hook coverage (annotating new routes with `->returns()` + regenerating) is a separate, later
 * operation; chaining both into one would make rollback ambiguous (which half failed?).
 *
 * **No PHP-side static analysis** (decision 3): the one safety check this needs — does the old
 * hand-written call construct query params beyond path substitution — is computed by the Node script
 * while it's already parsing the file to compute the rewrite spans, not re-derived here. A disqualified
 * wrapper surfaces as an advisory (WARN, null-suggestion) finding instead of a fixable one.
 *
 * **No new Operation class** (reuses `literal-rewrite`, decision 4): the scan (in `@splicewire/beam-ux`'s
 * `beam-ux-sdk-hook-migration` CLI, recast + babel-ts matching `blockdoc/lens.ts`) computes each edit's
 * exact `{file, old, new}` byte span; this audit just projects those into ordinary
 * `FixableFinding`/`OperationSuggestion` pairs and reuses surgeon's generic `LiteralRewriteOperation` /
 * `surgeon:rewrite` to apply — the same mechanism {@see SdkEndpointDriftAudit} nominates.
 *
 * **Not atomic across a candidate's edits.** A migration is 1 wrapper-deletion + N import-repoint edits,
 * emitted as N+1 separate findings (one flat `{file,old,new}` payload each, matching the established
 * `literal-rewrite` payload shape — no bespoke multi-edit convention introduced). Applying only some of a
 * candidate's findings breaks the build; gather every finding sharing a `functionName` before running
 * `surgeon:rewrite --apply`.
 */
class SdkHookMigrationAudit implements DoctorAudit, SuggestsOperations
{
    public const CHECK = 'sdk.hook-migration';

    /** @param  list<string>  $excludeDirNames */
    public function __construct(
        protected SdkHookMigrationBridge $bridge,
        protected string $hooksDir,
        protected string $apiRoot,
        protected string $importerRoot,
        protected array $excludeDirNames = ['credits'],
        protected ?string $routesManifestPath = null,
    ) {}

    /**
     * The default wiring against this app's generated-SDK layout: `hooksDir`/`apiRoot` derive from
     * `beam.client.out_dir` (the same setting `splicewire:beam:generate:client` writes to) so this
     * audit stays in step with wherever a host's generated client actually lands. `routesManifestPath`
     * is `<out_dir>/routes.ts` — the `name → path-template` map the same command emits alongside the
     * hooks (v2: recognizes plain string/template-literal path call sites, not just `route('name')`).
     */
    public static function forApp(SdkHookMigrationBridge $bridge, ?string $outDir = null): self
    {
        $outDir ??= (string) config('beam.client.out_dir');
        $uiSrc = dirname($outDir); // out_dir is `<ui/src>/generated` — its parent is `<ui/src>`.

        return new self(
            $bridge,
            hooksDir: $outDir.'/hooks',
            apiRoot: $uiSrc.'/features',
            importerRoot: $uiSrc,
            excludeDirNames: (array) config('beam.client.surgeon.sdk_hook_migration.exclude_dirs', ['credits']),
            routesManifestPath: $outDir.'/routes.ts',
        );
    }

    /** @return list<Finding> */
    public function run(): array
    {
        return array_map(fn (FixableFinding $f) => $f->finding, $this->suggestOperations());
    }

    /**
     * **Degrade-not-crash:** the Node bridge parses every `importerRoot` file with babel-ts to find
     * import sites — including generated `.d.ts` output it never controls the syntactic validity of
     * (e.g. a duplicate `#[TypeScript]`-annotated PHP class pair emitting the same TS name twice, a
     * genuine parse error). A parse failure there is an estate-data problem, not this audit's own
     * malfunction — surface it as a single advisory (WARN, null-suggestion) Finding so one broken file
     * doesn't crash the entire `surgeon:audit` sweep and starve every other audit of its findings.
     */
    public function suggestOperations(): array
    {
        if (! $this->bridge->available()) {
            return [];
        }

        try {
            $report = $this->bridge->scan($this->hooksDir, $this->apiRoot, $this->importerRoot, $this->excludeDirNames, $this->routesManifestPath);
        } catch (Throwable $e) {
            return [new FixableFinding(
                Finding::warn(self::CHECK, sprintf(
                    'The beam-ux sdk-hook-migration bridge failed to scan (%s) — likely a syntactically invalid '.
                    'file under the importer root (e.g. a generated .d.ts with a duplicate declaration). '.
                    'This audit\'s findings are unavailable until the underlying file is fixed.',
                    $e->getMessage(),
                )),
                null,
            )];
        }

        return $this->suggestFor($report);
    }

    /**
     * The pure core — no bridge, no disk. Given the Node scan's decoded `{candidates, exceptions}`
     * report, produce one {@see FixableFinding} per edit (a `literal-rewrite`-fixable migration step) and
     * one advisory (null-suggestion, WARN) {@see FixableFinding} per exception. Directly unit testable
     * with an in-memory report — no Node, no filesystem.
     *
     * @param  array{candidates?: list<array{functionName: string, routeName: string, apiFile: string, hookModule: string, edits: list<array{file: string, old: string, new: string}>}>, exceptions?: list<array{functionName: string, routeName: string, apiFile: string, reason: string}>}  $report
     * @return list<FixableFinding>
     */
    public function suggestFor(array $report): array
    {
        $findings = [];

        foreach ($report['candidates'] ?? [] as $candidate) {
            foreach ($candidate['edits'] as $edit) {
                $isWrapperDeletion = $edit['file'] === $candidate['apiFile'];
                $action = $isWrapperDeletion
                    ? 'retire the hand-written wrapper in favor of'
                    : 'repoint the '.basename((string) $edit['file']).' import to';

                $findings[] = new FixableFinding(
                    Finding::fail(self::CHECK, sprintf(
                        '%s (route %s) in %s: %s @/generated/hooks/%s.',
                        $candidate['functionName'],
                        $candidate['routeName'],
                        basename((string) $candidate['apiFile']),
                        $action,
                        $candidate['hookModule'],
                    )),
                    new OperationSuggestion(
                        kind: 'literal-rewrite',
                        summary: $isWrapperDeletion
                            ? "Retire hand-written {$candidate['functionName']} — @/generated/hooks/{$candidate['hookModule']} already covers it"
                            : "Repoint {$candidate['functionName']} import to @/generated/hooks/{$candidate['hookModule']} in ".basename((string) $edit['file']),
                        payload: [
                            'file' => $edit['file'],
                            'old' => $edit['old'],
                            'new' => $edit['new'],
                        ],
                    ),
                );
            }
        }

        foreach ($report['exceptions'] ?? [] as $exception) {
            $findings[] = new FixableFinding(
                Finding::warn(self::CHECK, sprintf(
                    '%s (route %s) in %s has a generated hook but was not migrated: %s',
                    $exception['functionName'],
                    $exception['routeName'],
                    basename((string) $exception['apiFile']),
                    $exception['reason'],
                )),
                null,
            );
        }

        return $findings;
    }
}
