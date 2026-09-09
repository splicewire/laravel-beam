<?php

namespace Splicewire\Beam\Surgeon;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\BeamDoctorManifest;

/**
 * **`beam.install.desired-state`** — what a correct beam-tier install produces, as a program rather
 * than as prose (`prove-the-beam-starter` 01 ruling 1, built by 12).
 *
 * The definition it encodes was **measured, not designed**: a clean-room install of
 * `laravel-beam-starter` from a bare tarball, `~/Workspaces` unreachable, is what produced R1–R7 and
 * the recorded figures beside them. This class is the executable copy of that page, so the definition
 * travels with the package to every host instead of rotting in a ticket that was already re-measured
 * three times in four days.
 *
 * ## Advisory, permanently — every requirement is a fact about a HOST
 *
 * `AGENTS.md`: *throw only on what the declaration's author could have got right without knowing which
 * host would load it.* Not one of R1–R7 qualifies. *Did `composer setup` exit 0*, *does `/docs` serve*,
 * *did `pnpm install` succeed* are all answers about a host, and half of them are about a **process
 * that already exited**. So this audit is registered with `gate: false` and every finding it can emit
 * is `Pass` or `Warn`; it never returns `Fail`, and no exit code depends on it. There is no argued
 * exception.
 *
 * ## ⚠️ The three-way read, which is the whole point of the class
 *
 * A doctor audit that cannot reach its subject and reports `Pass` anyway is the estate's signature
 * defect, and an audit encoding *"the install worked"* is exactly where it would hide. So each
 * requirement resolves to one of three readings, and the rule is stated once here rather than
 * re-argued per branch:
 *
 *   - **`Finding::warn()` — measured, and the requirement cannot hold.** A necessary condition is
 *     violated. Conclusive: the audit looked and found the subject wrong.
 *   - **`Finding::pass()` — measured, and satisfied.** Reserved for a condition that is necessary AND
 *     (absent a bug in code this package's own suite covers) sufficient. Only R5 and R6 can reach it
 *     from a booted app.
 *   - **`Finding::inconclusive()` — the audit reached only a necessary condition, or none at all.**
 *     Reports `Pass`, gates nothing, and carries `conclusive: false`, so a renderer prints *"measured
 *     nothing"* instead of a green tick over a check that never ran. R1, R2, R3, R4 and R7 live here
 *     in their satisfied state, and every one of their details names the command that would close the
 *     gap. **An unmeasurable requirement must not read the same as a satisfied one.**
 *
 * ## Per requirement — what it compiles to, and why
 *
 * **R1 · `composer setup` completes, every step.** The process is gone by the time any audit runs; a
 * booted app cannot see an exit status. What it CAN see is setup's residue — `.env`, an app key, a
 * reachable database with a non-empty migration ledger, `node_modules/`, a Vite manifest. Any residue
 * missing is a conclusive Warn (setup did not complete, or was undone since). Every residue present is
 * **inconclusive**: it is consistent with a clean run and with a run that failed on its last step
 * having already produced everything earlier steps write. The step COUNT is read out of the host's own
 * `composer.json`, not hard-coded — 01 wrote "seven steps" and the file has eight.
 *
 * **R2 · the doctor reports zero ERRORs.** Self-referential, and it is resolved by refusing rather than
 * by approximating. This audit runs INSIDE the run it would have to count, so at the moment `run()` is
 * called the count is structurally incomplete — every audit after this one in the manifest has not
 * reported yet, this one included. A number produced here would be a number about a prefix of the run,
 * spelled like a number about the run. It is therefore **always inconclusive**, and the detail carries
 * the extractor an operator runs against the finished output. This is the only requirement whose
 * reading never varies with the host.
 *
 * **R3 · `composer ci:check` exits 0.** It runs a full suite plus pint and phpstan; invoking it here
 * would make every doctor run multi-minute, and a doctor an operator stops running is worse than no
 * doctor. So this audit **never runs it** and reads a RECORD a host may write instead
 * (`beam.core.install.desired_state.ci_check_record`, default `storage/app/beam/ci-check.json`;
 * `{"exit": 0, "at": "…", "commit": "…"}`). No record → inconclusive, naming the path. A record whose
 * `commit` is not this tree's HEAD → **Warn**, because a stale pass and a fresh pass must not be spelled
 * the same, and the only spelling this audit can ever use is *recorded*. Nothing here reports a
 * ci:check that was run now, because nothing here runs one.
 *
 * **R4 · `/` returns 200 from a real HTTP server.** The status code is a live-server fact. The audit
 * matches `/` against the booted route table: no match is a conclusive Warn; a match is inconclusive
 * UNLESS the matched handler is beam-ux's public-entry catch-all, in which case the R5 instrument below
 * settles it. At the starter `/` is a host closure, so this reads inconclusive there — correctly.
 *
 * **R5 · `/docs`, `/docs/api`, `/docs/mcp` return 200.** ⚠️ The route table CANNOT answer this and the
 * obvious probe lies: all three match `{path}`, beam-ux's catch-all, so route resolution reports
 * success for a URL that does not exist. The discriminating instrument is the resolver the controller
 * itself uses — `PublicEntryGate::chainFor($path, 'site', null)` as a GUEST, which returns null for
 * exactly the unresolvable / unpublished / denied set the controller answers 404 to. A non-null chain
 * is necessary and sufficient for the controller not to 404, so this reading is **conclusive** in both
 * directions. Where beam-ux is not installed, or the database is unreachable, it degrades to
 * inconclusive rather than fabricating either answer.
 *
 * **R6 · an auth-gated frame surface redirects rather than 500s.** Conclusive when the probe path
 * resolves to a route gathering an `auth`-family middleware: the guard runs BEFORE the controller, so a
 * guest is redirected and the controller's failure modes are unreachable on that path. Resolving with
 * no auth middleware is inconclusive (a guest reaches the controller and the outcome is live); not
 * resolving at all is a Warn.
 *
 * **R7 · `pnpm install --frozen-lockfile` succeeds with no workspace access.** ⚠️ 01's amendment is
 * load-bearing: run in order, after setup's own `pnpm install`, this returns exit 0 / *"Already up to
 * date"* / 428ms — **it passes by skipping**, which is the defect the whole map exists to hunt sitting
 * inside the definition. It means something only from a tree with no `node_modules`. The audit measures
 * the half that is a declaration: a committed lockfile, and no dependency declared through a
 * `workspace:` / `link:` / `file:` / `portal:` protocol that would reach outside the tree. Either
 * violated is a conclusive Warn. Otherwise inconclusive — and the detail says whether `node_modules`
 * is present, because that is precisely what would make a naive re-run meaningless.
 *
 * ## The recorded figures — figures, with their extractors, and no threshold
 *
 * 01 ruling 4: record the numbers, require no floor. *A bound sitting above both the defect and the fix
 * passes identically either way*, and the estate has measured that exact comment-shaped guard. So the
 * final finding prints this host's live figures beside the clean-install baseline and gates nothing; a
 * shrink is visible to a reader and fails nothing. Each figure names its extractor in {@see BASELINE},
 * because three of 01's six recorded numbers had already drifted by the time 07 re-read them and a
 * number whose instrument is not written down is a property of the instrument.
 *
 * ⚠️ **01's `194 WARN` is not reproducible and is NOT encoded.** Re-derived 2026-09-08 for this class at
 * the clean-room tree with the extractor named in {@see BASELINE} — `0 ERROR / 154 WARN / 89 INFO`,
 * agreeing with 07's independent reading and not with 01's. 154 is what ships here.
 *
 * Registered on {@see BeamDoctorManifest} only (registering in both places double-renders it), bound
 * lazily off the container because it reads the finished route table.
 */
class InstallDesiredStateAudit implements DoctorAudit
{
    public const CHECK_R1 = 'beam.install.desired-state.r1-setup-completed';

    public const CHECK_R2 = 'beam.install.desired-state.r2-doctor-zero-errors';

    public const CHECK_R3 = 'beam.install.desired-state.r3-ci-check';

    public const CHECK_R4 = 'beam.install.desired-state.r4-root-route';

    public const CHECK_R5 = 'beam.install.desired-state.r5-docs-entries';

    public const CHECK_R6 = 'beam.install.desired-state.r6-frame-surface';

    public const CHECK_R7 = 'beam.install.desired-state.r7-pnpm-frozen-lockfile';

    public const CHECK_RECORDED = 'beam.install.desired-state.recorded';

    /** The public-entry controller whose catch-all mount cannot be read as evidence of a 200. */
    public const ENTRY_CONTROLLER = 'Splicewire\Beam\Ux\Http\Controllers\PublicEntryController';

    /** Dependency protocols that reach OUTSIDE the tree — the thing R7's "no workspace access" forbids. */
    public const OUTSIDE_TREE_PROTOCOLS = ['workspace:', 'link:', 'file:', 'portal:'];

    /**
     * The clean-room baseline, each figure beside the extractor that produced it. Descriptive: nothing
     * compares against these and nothing may. Substrate: `git archive HEAD` of `laravel-beam-starter`
     *
     * @ c94b44f into `/private/tmp`, `composer setup --no-interaction`, `~/Workspaces` unreachable.
     *
     * @var array<string, array{value: int|string, extractor: string, on: string}>
     */
    public const BASELINE = [
        'doctor ERROR / WARN / INFO' => [
            'value' => '0 / 154 / 89',
            // --no-ansi rather than an ANSI-stripping regex on purpose: the estate's own reading of this
            // figure was taken through `perl -pe 's/<esc>...//g'`, and a backslash-dense extractor does not
            // survive being printed by a console renderer, so an operator copying it out of this output
            // would run a different command than the one that produced the number. Verified equal to the
            // perl-stripped reading at ~/Herd/beam, 2026-09-08: 2 / 175 / 96 both ways.
            'extractor' => "artisan splicewire:beam:doctor --no-ansi | grep -c -E '^[[:space:]]*ERROR[[:space:]]'  (and WARN, INFO)",
            'on' => '2026-09-08',
        ],
        'routes registered' => [
            'value' => 78,
            'extractor' => "count(app('router')->getRoutes())",
            'on' => '2026-09-08',
        ],
        'tables' => [
            'value' => 41,
            'extractor' => 'count(Schema::getTableListing())',
            'on' => '2026-09-08',
        ],
        // NOT 07's "migrations published by the install" (14, then 21 on 01) — a different quantity, and
        // conflating them is how that column drifted. This counts every migration file in the tree, the
        // starter's committed ones included, at the clean-room root itself. Measured there, not at ~/Herd/beam,
        // which reads 27 because it is a differently-migrated host.
        'migration files on disk' => [
            'value' => 41,
            'extractor' => "glob('database/migrations/*.php') + glob('database/migrations/*/*.php')",
            'on' => '2026-09-08',
        ],
        'beam_ux_entries rows' => [
            'value' => 13,
            'extractor' => "DB::table('beam_ux_entries')->count()",
            'on' => '2026-09-08',
        ],
        'suite' => [
            'value' => '92 tests, 509 assertions',
            'extractor' => "composer test — NOT run by this audit; recorded from 07's clean-room run",
            'on' => '2026-09-08',
        ],
    ];

    /**
     * @param  list<string>  $setupSteps  the host's own `composer.json` `scripts.setup` entries
     * @param  array<string, bool|null>  $setupResidues  label → present; null = could not be determined
     * @param  array{exit?: int|string, at?: string, commit?: string}|null  $ciCheckRecord
     * @param  array<string, string|null>  $docsResolution  path → 'resolved'|'unresolved'|null (unmeasurable)
     * @param  list<string>  $frameRouteMiddleware
     * @param  list<string>  $outsideTreeDeps  package.json entries declared through an outside-tree protocol
     * @param  array<string, int|string|null>  $figures
     */
    public function __construct(
        protected array $setupSteps,
        protected array $setupResidues,
        protected ?array $ciCheckRecord,
        protected string $ciCheckRecordPath,
        protected ?string $headCommit,
        protected ?string $rootRouteAction,
        protected bool $rootServedByEntryCatchAll,
        protected array $docsResolution,
        protected ?string $frameProbePath,
        protected ?string $frameRouteUri,
        protected array $frameRouteMiddleware,
        protected bool $lockfilePresent,
        protected array $outsideTreeDeps,
        protected bool $nodeModulesPresent,
        protected array $figures,
    ) {}

    public static function forApp(): self
    {
        $docsPaths = self::configuredList('beam.core.install.desired_state.docs_paths', ['docs', 'docs/api', 'docs/mcp']);
        $frameProbe = config('beam.core.install.desired_state.frame_probe_path', '/frame/resources/tokens');
        $frameProbe = is_string($frameProbe) && $frameProbe !== '' ? $frameProbe : null;

        $recordPath = config('beam.core.install.desired_state.ci_check_record');
        $recordPath = is_string($recordPath) && $recordPath !== '' ? $recordPath : storage_path('app/beam/ci-check.json');

        $root = self::matchRoute('/');
        $frame = $frameProbe !== null ? self::matchRoute($frameProbe) : ['action' => null, 'uri' => null, 'middleware' => []];

        return new self(
            setupSteps: self::composerSetupSteps(),
            setupResidues: self::residues(),
            ciCheckRecord: self::readJson($recordPath),
            ciCheckRecordPath: $recordPath,
            headCommit: self::headCommit(),
            rootRouteAction: $root['action'],
            rootServedByEntryCatchAll: $root['action'] !== null && str_starts_with($root['action'], self::ENTRY_CONTROLLER),
            docsResolution: self::resolveDocs($docsPaths),
            frameProbePath: $frameProbe,
            frameRouteUri: $frame['uri'],
            frameRouteMiddleware: $frame['middleware'],
            lockfilePresent: is_file(base_path('pnpm-lock.yaml')),
            outsideTreeDeps: self::outsideTreeDeps(),
            nodeModulesPresent: is_dir(base_path('node_modules')),
            figures: self::figures(),
        );
    }

    /** @return list<Finding> */
    public function run(): array
    {
        return [
            $this->r1(),
            $this->r2(),
            $this->r3(),
            $this->r4(),
            $this->r5(),
            $this->r6(),
            $this->r7(),
            $this->recorded(),
        ];
    }

    /* ------------------------------------------------------------------ R1 */

    protected function r1(): Finding
    {
        $steps = count($this->setupSteps);
        $stepPhrase = $steps > 0
            ? sprintf('%d step(s), read from this host\'s composer.json `scripts.setup`', $steps)
            : 'step count unreadable (no `scripts.setup` in this host\'s composer.json)';

        $missing = array_keys(array_filter($this->setupResidues, fn (?bool $present) => $present === false));
        $unknown = array_keys(array_filter($this->setupResidues, fn (?bool $present) => $present === null));

        if ($missing !== []) {
            return Finding::warn(self::CHECK_R1, sprintf(
                'R1 (`composer setup` completes, %s): setup residue MISSING — %s. Whatever the run reported, '.
                'this host is not in the state a completed setup leaves behind. Re-run `composer setup --no-interaction` '.
                '(no `--force`: it republishes over committed tier gates).',
                $stepPhrase,
                implode('; ', $missing),
            ));
        }

        return Finding::inconclusive(self::CHECK_R1, sprintf(
            'R1 (`composer setup` completes, %s): every residue a completed setup leaves is present (%s)%s — but a '.
            'booted app cannot see the exit status of a process that has already ended, and this state is also '.
            'consistent with a run that failed on its LAST step. Not measured here. The instrument is the run itself: '.
            '`composer setup --no-interaction; echo $?`.',
            $stepPhrase,
            implode(', ', array_keys(array_filter($this->setupResidues, fn (?bool $p) => $p === true))),
            $unknown === [] ? '' : sprintf('; undetermined: %s', implode(', ', $unknown)),
        ));
    }

    /* ------------------------------------------------------------------ R2 */

    /**
     * Always inconclusive, and the reason is structural rather than circumstantial — see the class
     * docblock. Approximating it (counting the findings reported so far) would produce a number about a
     * PREFIX of the run wearing the spelling of a number about the run, which is the exact substitution
     * this audit exists to refuse.
     */
    protected function r2(): Finding
    {
        return Finding::inconclusive(self::CHECK_R2, sprintf(
            'R2 (`splicewire:beam:doctor` reports zero ERRORs): NOT MEASURABLE FROM HERE, and deliberately not '.
            'approximated — this audit runs inside the very run it would have to count, so at this moment the count '.
            'is incomplete by construction (every audit after this one, this one included, has not reported yet). '.
            'Required value: 0 ERRORs. Extractor, against the finished output: `%s`. Clean-room baseline %s (%s), '.
            'recorded and gating nothing.',
            self::BASELINE['doctor ERROR / WARN / INFO']['extractor'],
            self::BASELINE['doctor ERROR / WARN / INFO']['value'],
            self::BASELINE['doctor ERROR / WARN / INFO']['on'],
        ));
    }

    /* ------------------------------------------------------------------ R3 */

    protected function r3(): Finding
    {
        if ($this->ciCheckRecord === null) {
            return Finding::inconclusive(self::CHECK_R3, sprintf(
                'R3 (`composer ci:check` exits 0): NOT RUN — this audit never shells out to it, because ci:check runs '.
                'the whole suite plus pint and phpstan and a multi-minute doctor is a doctor nobody runs. No recorded '.
                'result at %s either, so this requirement is unmeasured. Run `composer ci:check` (exit 0 required; 2 '.
                'means a gate could not be measured, which is not a verdict), or have it write '.
                '{"exit":0,"at":"<iso8601>","commit":"<sha>"} there.',
                $this->ciCheckRecordPath,
            ));
        }

        $exit = $this->ciCheckRecord['exit'] ?? null;
        $at = (string) ($this->ciCheckRecord['at'] ?? 'an unrecorded time');
        $commit = isset($this->ciCheckRecord['commit']) ? (string) $this->ciCheckRecord['commit'] : null;

        if ($exit === null || ! is_numeric($exit)) {
            return Finding::warn(self::CHECK_R3, sprintf(
                'R3 (`composer ci:check` exits 0): the record at %s carries no numeric `exit`, so it says nothing. '.
                'A malformed record is not a pass.',
                $this->ciCheckRecordPath,
            ));
        }

        $exit = (int) $exit;

        // A stale pass and a fresh pass must not be spelled the same. This audit has only one spelling —
        // "recorded" — and a record taken against a different tree gets a Warn rather than a softer word.
        if ($commit === null || $this->headCommit === null || $commit !== $this->headCommit) {
            return Finding::warn(self::CHECK_R3, sprintf(
                'R3 (`composer ci:check` exits 0): the record at %s is STALE or unattributable — it reports exit %d, '.
                'recorded %s against commit %s, while this tree is at %s. A recorded pass from another commit is not '.
                'evidence about this one. Re-run `composer ci:check`.',
                $this->ciCheckRecordPath,
                $exit,
                $at,
                $commit ?? '(none)',
                $this->headCommit ?? '(unreadable)',
            ));
        }

        if ($exit !== 0) {
            return Finding::warn(self::CHECK_R3, sprintf(
                'R3 (`composer ci:check` exits 0): RECORDED FAILURE — exit %d at %s against this tree\'s HEAD (%s). '.
                '%s',
                $exit,
                $at,
                $commit,
                $exit === 2
                    ? 'Exit 2 is `bin/ci-check`\'s UNMEASURED state (a gate could not be spawned, wrote zero bytes, or '.
                      'exited 0 without a summary) — not a verdict, and it dominates 1.'
                    : 'Exit 1 is a verdict: at least one gate ran and failed.',
            ));
        }

        return Finding::pass(self::CHECK_R3, sprintf(
            'R3 (`composer ci:check` exits 0): RECORDED pass — exit 0 at %s against this tree\'s HEAD (%s). This audit '.
            'did not re-run it; the reading is as fresh as the record.',
            $at,
            $commit,
        ));
    }

    /* ------------------------------------------------------------------ R4 */

    protected function r4(): Finding
    {
        if ($this->rootRouteAction === null) {
            return Finding::warn(self::CHECK_R4, 'R4 (`/` returns 200 from a real HTTP server): NO ROUTE in the booted '.
                'route table matches `/`, so it cannot return 200 — it can only 404.');
        }

        if ($this->rootServedByEntryCatchAll) {
            $rootResolution = $this->docsResolution[''] ?? null;

            if ($rootResolution === 'unresolved') {
                return Finding::warn(self::CHECK_R4, 'R4 (`/` returns 200): `/` is served by beam-ux\'s public-entry '.
                    'catch-all and the guest entry chain for `` is null — the controller answers 404.');
            }

            if ($rootResolution === 'resolved') {
                return Finding::pass(self::CHECK_R4, 'R4 (`/` returns 200): `/` is served by beam-ux\'s public-entry '.
                    'catch-all and resolves to a published site entry for a GUEST, which is the condition the '.
                    'controller 404s on. Measured through the resolver, not through the route table.');
            }
        }

        return Finding::inconclusive(self::CHECK_R4, sprintf(
            'R4 (`/` returns 200 from a real HTTP server): `/` resolves to %s in the booted route table — necessary, '.
            'and not sufficient. The handler is host-owned, so the status code is a live fact this audit does not '.
            'measure. Close it with `php artisan serve` and `curl -o /dev/null -w \'%%{http_code}\' http://127.0.0.1:8000/`.',
            $this->rootRouteAction,
        ));
    }

    /* ------------------------------------------------------------------ R5 */

    protected function r5(): Finding
    {
        $probed = array_diff_key($this->docsResolution, ['' => null]);

        if ($probed === []) {
            return Finding::inconclusive(self::CHECK_R5, 'R5 (`/docs`, `/docs/api`, `/docs/mcp` return 200): no docs '.
                'paths are configured for this host (`beam.core.install.desired_state.docs_paths` is empty), so '.
                'nothing was probed.');
        }

        $unmeasurable = array_keys(array_filter($probed, fn (?string $r) => $r === null));

        if (count($unmeasurable) === count($probed)) {
            return Finding::inconclusive(self::CHECK_R5, sprintf(
                'R5 (`/docs`, `/docs/api`, `/docs/mcp` return 200): NOT MEASURED — %s. NOTE: the route table cannot '.
                'answer this either: all three match beam-ux\'s `{path}` catch-all, so a route-table probe reports '.
                'success for a URL that does not exist.',
                $this->unmeasurableReason(),
            ));
        }

        $unresolved = array_keys(array_filter($probed, fn (?string $r) => $r === 'unresolved'));

        if ($unresolved !== []) {
            return Finding::warn(self::CHECK_R5, sprintf(
                'R5 (`/docs`, `/docs/api`, `/docs/mcp` return 200): %s does not resolve to a published site entry for '.
                'a GUEST, so `PublicEntryController` answers 404 — the README\'s headline claim, "the site '.
                'self-documents on first boot", does not hold here. Re-run `php artisan splicewire:beam:install` (its '.
                'ux seeders write these entries) and `php artisan splicewire:beam:ux:compile`.%s',
                implode(', ', array_map(fn (string $p) => "`/{$p}`", $unresolved)),
                $unmeasurable === [] ? '' : sprintf(' (%s not measurable: %s)', implode(', ', $unmeasurable), $this->unmeasurableReason()),
            ));
        }

        $resolved = array_keys(array_filter($probed, fn (?string $r) => $r === 'resolved'));

        $detail = sprintf(
            'R5 (`/docs`, `/docs/api`, `/docs/mcp` return 200): %s each resolve to a published site entry for a GUEST '.
            'through `PublicEntryGate::chainFor()` — the same resolver the controller uses, and a null chain is '.
            'exactly what it 404s on. NOTE: measured through the resolver deliberately: all three also match beam-ux\'s '.
            '`{path}` catch-all, so a route-table probe would have reported success for a URL that does not exist.',
            implode(', ', array_map(fn (string $p) => "`/{$p}`", $resolved)),
        );

        return $unmeasurable === []
            ? Finding::pass(self::CHECK_R5, $detail)
            : Finding::inconclusive(self::CHECK_R5, $detail.sprintf(' %s not measurable: %s.', implode(', ', $unmeasurable), $this->unmeasurableReason()));
    }

    /* ------------------------------------------------------------------ R6 */

    protected function r6(): Finding
    {
        if ($this->frameProbePath === null) {
            return Finding::inconclusive(self::CHECK_R6, 'R6 (an auth-gated frame surface redirects rather than 500s): '.
                'no probe path configured (`beam.core.install.desired_state.frame_probe_path`).');
        }

        if ($this->frameRouteUri === null) {
            return Finding::warn(self::CHECK_R6, sprintf(
                'R6 (an auth-gated frame surface redirects rather than 500s): NO ROUTE matches `%s`, so it 404s rather '.
                'than redirecting. The frame surface is not mounted at this host.',
                $this->frameProbePath,
            ));
        }

        $auth = array_values(array_filter(
            $this->frameRouteMiddleware,
            fn (string $m) => $m === 'auth' || str_starts_with($m, 'auth:') || str_contains($m, 'Authenticate'),
        ));

        if ($auth === []) {
            return Finding::inconclusive(self::CHECK_R6, sprintf(
                'R6 (an auth-gated frame surface redirects rather than 500s): `%s` resolves to `%s`, but the route '.
                'gathers no auth-family middleware (%s) — a guest reaches the controller, so whether the response is a '.
                'redirect, a 200 or a 500 is a live fact this audit does not measure.',
                $this->frameProbePath,
                $this->frameRouteUri,
                $this->frameRouteMiddleware === [] ? 'none' : implode(', ', $this->frameRouteMiddleware),
            ));
        }

        return Finding::pass(self::CHECK_R6, sprintf(
            'R6 (an auth-gated frame surface redirects rather than 500s): `%s` resolves to `%s` behind %s. The guard '.
            'runs BEFORE the controller, so an unauthenticated request is redirected and the controller\'s failure '.
            'modes are unreachable on this path.',
            $this->frameProbePath,
            $this->frameRouteUri,
            implode(', ', array_map(fn (string $m) => "`{$m}`", $auth)),
        ));
    }

    /* ------------------------------------------------------------------ R7 */

    protected function r7(): Finding
    {
        if (! $this->lockfilePresent) {
            return Finding::warn(self::CHECK_R7, 'R7 (`pnpm install --frozen-lockfile` succeeds with no workspace '.
                'access, from a tree with NO `node_modules`): there is no `pnpm-lock.yaml` in this tree, so '.
                '`--frozen-lockfile` cannot succeed at all.');
        }

        if ($this->outsideTreeDeps !== []) {
            return Finding::warn(self::CHECK_R7, sprintf(
                'R7 (`pnpm install --frozen-lockfile` succeeds with NO WORKSPACE ACCESS): %d dependency declaration(s) '.
                'reach outside this tree — %s. A clone without the workspace beside it cannot install.',
                count($this->outsideTreeDeps),
                implode(', ', $this->outsideTreeDeps),
            ));
        }

        return Finding::inconclusive(self::CHECK_R7, sprintf(
            'R7 (`pnpm install --frozen-lockfile`, from a tree with NO `node_modules`): the DECLARATION half is clean '.
            '— `pnpm-lock.yaml` is present and no dependency uses %s. The install itself is a process this audit '.
            'cannot run. NOTE: re-running it here would not answer it: `node_modules` is %s, and from a warm tree '.
            'this command returns exit 0 / "Already up to date" in well under a second — it passes by SKIPPING. It '.
            'means something only from a cold tree.',
            implode(' / ', array_map(fn (string $p) => "`{$p}`", self::OUTSIDE_TREE_PROTOCOLS)),
            $this->nodeModulesPresent ? 'PRESENT here, so this tree is warm' : 'absent here, so this tree is cold',
        ));
    }

    /* ------------------------------------------------- the recorded figures */

    /**
     * Descriptive only. 01 ruling 4 forbids a floor on any of these, so nothing here compares — it
     * prints this host's reading beside the clean-room baseline and each extractor, and a reader does
     * the comparing. A shrink is visible and fails nothing; that is deliberate.
     */
    protected function recorded(): Finding
    {
        $rows = [];

        foreach (self::BASELINE as $label => $baseline) {
            $live = $this->figures[$label] ?? null;
            $rows[] = sprintf(
                '%s: here %s / clean-room %s [%s]',
                $label,
                $live === null ? 'not measured' : (string) $live,
                (string) $baseline['value'],
                $baseline['extractor'],
            );
        }

        return Finding::pass(self::CHECK_RECORDED, sprintf(
            'RECORDED, gating nothing (01 ruling 4 — no floor, because a bound above both the defect and the fix '.
            'passes either way). %s',
            implode(' · ', $rows),
        ));
    }

    protected function unmeasurableReason(): string
    {
        if (! class_exists(self::ENTRY_CONTROLLER)) {
            return 'splicewire/laravel-beam-ux is not installed at this host, so there is no public-entry resolver to ask';
        }

        return 'the entry resolver could not be reached (no database connection, or no `beam_ux_entries` table)';
    }

    /* ------------------------------------------------------- gathering side */

    /** @return list<string> */
    protected static function composerSetupSteps(): array
    {
        $manifest = self::readJson(base_path('composer.json'));
        $steps = $manifest['scripts']['setup'] ?? [];

        return is_array($steps) ? array_values(array_map('strval', $steps)) : [];
    }

    /** @return array<string, bool|null> */
    protected static function residues(): array
    {
        $ledger = null;

        try {
            $ledger = DB::connection()->table('migrations')->count() > 0;
        } catch (\Throwable) {
            $ledger = null;
        }

        return [
            '.env' => is_file(base_path('.env')),
            'app key' => is_string(config('app.key')) && config('app.key') !== '',
            'a non-empty migration ledger' => $ledger,
            'node_modules/' => is_dir(base_path('node_modules')),
            'a built Vite manifest' => is_file(public_path('build/manifest.json')) || is_file(public_path('build/.vite/manifest.json')),
        ];
    }

    /**
     * The guest entry chain for each path, plus `''` (the site root) so R4 can use the same instrument
     * when `/` is served by the catch-all. `null` means the question could not be asked at all.
     *
     * @param  list<string>  $paths
     * @return array<string, string|null>
     */
    protected static function resolveDocs(array $paths): array
    {
        $out = [];

        foreach (['', ...$paths] as $path) {
            $out[$path] = null;
        }

        $gate = 'Splicewire\Beam\Ux\Http\PublicEntryGate';
        $entry = 'Splicewire\Beam\Ux\Models\BeamUxEntry';

        if (! class_exists($gate) || ! class_exists($entry)) {
            return $out;
        }

        try {
            $resolver = app($gate);
            $realm = constant($entry.'::REALM_SITE');

            foreach (array_keys($out) as $path) {
                $out[$path] = $resolver->chainFor($path, $realm, null) === null ? 'unresolved' : 'resolved';
            }
        } catch (\Throwable) {
            // Degrade, never fabricate: an unreachable resolver reports "not measured", not "clean".
            foreach (array_keys($out) as $path) {
                $out[$path] = null;
            }
        }

        return $out;
    }

    /**
     * Match a URI against the BOOTED route table. A miss throws `NotFoundHttpException`, which is the
     * honest negative — but note a hit is weaker than it looks wherever a wildcard mount is in play,
     * which is why R5 does not use this.
     *
     * @return array{action: string|null, uri: string|null, middleware: list<string>}
     */
    protected static function matchRoute(string $uri): array
    {
        try {
            $route = app('router')->getRoutes()->match(Request::create($uri, 'GET'));
        } catch (\Throwable) {
            return ['action' => null, 'uri' => null, 'middleware' => []];
        }

        $action = $route->getActionName();

        return [
            'action' => is_string($action) ? $action : 'Closure',
            'uri' => $route->uri(),
            'middleware' => array_values(array_map('strval', $route->gatherMiddleware())),
        ];
    }

    /** @return list<string> */
    protected static function outsideTreeDeps(): array
    {
        $manifest = self::readJson(base_path('package.json'));

        if ($manifest === null) {
            return [];
        }

        $found = [];

        foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'] as $section) {
            foreach ((array) ($manifest[$section] ?? []) as $name => $constraint) {
                if (! is_string($constraint)) {
                    continue;
                }

                foreach (self::OUTSIDE_TREE_PROTOCOLS as $protocol) {
                    if (str_starts_with($constraint, $protocol)) {
                        $found[] = sprintf('%s.%s = %s', $section, $name, $constraint);
                    }
                }
            }
        }

        return $found;
    }

    /** @return array<string, int|string|null> */
    protected static function figures(): array
    {
        $figures = [
            'routes registered' => count(app('router')->getRoutes()),
            'tables' => null,
            'migration files on disk' => count(glob(base_path('database/migrations/*.php')) ?: [])
                + count(glob(base_path('database/migrations/*/*.php')) ?: []),
            'beam_ux_entries rows' => null,
            'suite' => null,
            'doctor ERROR / WARN / INFO' => null,
        ];

        try {
            $figures['tables'] = sprintf('%d (%s)', count(Schema::getTableListing()), DB::connection()->getDriverName());
        } catch (\Throwable) {
            // left null — "not measured", which is what the finding prints
        }

        try {
            $figures['beam_ux_entries rows'] = DB::connection()->table('beam_ux_entries')->count();
        } catch (\Throwable) {
            // left null
        }

        return $figures;
    }

    /** HEAD as a sha, read from `.git` without shelling out. Null when this tree is not a git checkout. */
    protected static function headCommit(): ?string
    {
        $head = base_path('.git/HEAD');

        if (! is_readable($head)) {
            return null;
        }

        $contents = trim((string) file_get_contents($head));

        if (! str_starts_with($contents, 'ref: ')) {
            return preg_match('/^[0-9a-f]{40}$/', $contents) === 1 ? $contents : null;
        }

        $ref = base_path('.git/'.trim(substr($contents, 5)));

        return is_readable($ref) ? trim((string) file_get_contents($ref)) : null;
    }

    /** @return array<mixed>|null */
    protected static function readJson(string $path): ?array
    {
        if (! is_readable($path) || ! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    protected static function configuredList(string $key, array $default): array
    {
        $value = config($key);

        if (! is_array($value)) {
            return $default;
        }

        return array_values(array_map(fn ($p) => trim((string) $p, '/'), $value));
    }
}
