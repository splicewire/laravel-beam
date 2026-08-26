<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Popcorn\Registries\IsRegistry;
use Splicewire\Beam\Console\RegistryConformanceCommand;
use Splicewire\Beam\Surface\RuntimeCorroborator;
use Splicewire\Beam\Surgeon\UndeclaredSurfaceAudit;
use Splicewire\Beam\Surgeon\UndescribedRegistryAudit;

/**
 * The **advisory** half of registry-kernel ticket 35, and the place every judgement call lives.
 *
 * {@see RegistryConformanceAudit} gates a population that opted in by declaring `#[IsRegistry]`, which is
 * what lets it carry no suppression list at all. That property only survives if the *other* question —
 * "should this registry-shaped class be declared in the first place?" — has somewhere else to go. This is
 * that somewhere. It never gates.
 *
 * ## The structural test is not reimplemented here
 *
 * The shape test — container singleton + plural state + a write verb + a read verb — lives in
 * {@see UndescribedRegistryAudit} and is CONSUMED, not copied. Two copies of a heuristic drift, and the
 * exemptions, the verb lists and the ownership rule are decisions already made there. This is the same
 * relationship {@see RuntimeCorroborator} has with the undeclared-surface audit,
 * and for the same reason.
 *
 * What differs is the SCOPE. That audit derives its roots from index membership — the ratchet that makes it
 * survivable as a gate. This one is handed the whole host composition (see `forHost()`), because a report
 * that only looks where someone already opted in cannot report what nobody has opted into.
 *
 * ## Three dispositions, two homes, and the reason they are apart (14 D10)
 *
 * | Disposition | Meaning | Storage |
 * | --- | --- | --- |
 * | `whitelisted` | argued permanent, never migrating | {@see DEFAULT_WHITELIST}, argument inline per row |
 * | `deferred` | blocked on a NAMED OPEN ticket | the committed JSON artifact |
 * | `outstanding` | should migrate, hasn't | the committed JSON artifact |
 *
 * The permanent list is code and the moving list is an artifact, deliberately — the estate's own precedent
 * is {@see UndeclaredSurfaceAudit::DEFAULT_EXEMPT_URIS} beside
 * `.beam/undeclared-surface.json`. An argued permanent exemption belongs where its argument can be read in
 * review beside the code it excuses; a burn-down belongs where it ratchets. Putting the burn-down in a
 * `const` makes every improvement a code change; putting the permanent list in the artifact strips its
 * arguments and turns them into rows nobody can defend.
 *
 * A fourth value is not a disposition and has no storage: **`unaccounted`** is registry-shaped, undeclared
 * and in no bucket. It is the residue the map's completeness assertion is about, and running
 * `splicewire:beam:registry-conformance` is what empties it — by writing each row down as `outstanding`,
 * which is the point rather than a loophole. You cannot burn down what you have not counted.
 *
 * ## Three staleness findings, which are what stop the list rotting into blanket permission
 *
 * A suppression list nobody re-reads becomes permission for whatever drifts into it. All three of these are
 * mechanical and none needs a human to remember anything:
 *
 *   - a row naming a class that **no longer exists**, or that the scan no longer produces at all — the
 *     exemption outlived its subject;
 *   - a row naming a class that **now declares `#[IsRegistry]`** — the exemption outlived its argument;
 *   - a `deferred` row whose named ticket is **closed** — the deferral outlived its blocker, which is the
 *     precise failure mode "blocked on a ticket" invites.
 *
 * The third needs to read the tracker, so it is a seam ({@see $ticketStatus}). Where no tracker is
 * configured it reports NOTHING of that kind and says so once, rather than reporting every deferral as
 * stale or silently passing every deferral as fresh — an unanswerable question answered either way is worse
 * than an unanswerable question named.
 *
 * ## What this audit claims, and what it does not
 *
 * A composition, never the family (14 D12). `splicewire-app` is the composition the map closes against.
 * There is no cross-host aggregator here and there should not be: that is the ticket-03 census's job, and a
 * boot cannot do it.
 */
class UndeclaredRegistryShapeAudit implements DoctorAudit
{
    public const CHECK = 'registry.undeclared-shape';

    public const WHITELISTED = 'whitelisted';

    public const DEFERRED = 'deferred';

    public const OUTSTANDING = 'outstanding';

    public const UNACCOUNTED = 'unaccounted';

    public const CONFORMING = 'conforming';

    /** The subject class is gone from this composition entirely — the one expiry that frees the name. */
    public const EXPIRY_GONE = 'gone';

    /** The subject class is still here but is no longer registry-shaped, so the scan stopped producing it. */
    public const EXPIRY_UNSHAPED = 'unshaped';

    /**
     * Argued permanent exemptions: registry-shaped, undeclared, and staying that way. FQCN => the argument,
     * inline, in the author's own words. A row here is a claim someone can be held to in review.
     *
     * Empty on landing, and that is a real state rather than an unfinished one: ticket 21 measured beam-core's
     * registry-shaped-undeclared residue at ZERO, so beam is a clean baseline and the first row added here
     * will be news. Do not seed it with guesses to make the report look tidy — an unargued row is exactly
     * what the three staleness findings above exist to catch, and seeding is how a list starts rotting on
     * day one.
     *
     * @var array<string, string>
     */
    public const DEFAULT_WHITELIST = [];

    /**
     * @param  array<string, string>  $whitelist
     * @param  (callable(string): ?bool)|null  $ticketStatus  a ticket reference => is it still open? `null`
     *                                                        means unanswerable, and an unanswerable ticket
     *                                                        produces no staleness finding
     */
    public function __construct(
        protected UndescribedRegistryAudit $shape,
        protected string $artifactPath,
        protected array $whitelist = self::DEFAULT_WHITELIST,
        protected $ticketStatus = null,
    ) {}

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        if (! $this->shape->detectionAvailable()) {
            // Inherited from the scan this audit consumes: without nikic/php-parser there are no binding
            // call sites to read, so every bucket would read zero. Say so instead — an empty report and an
            // unread one look identical from the outside, which is the confusion this effort exists to end.
            return [Finding::warn(self::CHECK, sprintf(
                'Registry SHAPE is unread in this composition: the structural scan needs nikic/php-parser '.
                '(via rushing/laravel-surgeon) and it is not installed, so `%s` and `%s` are both vacuously '.
                'zero here.',
                self::UNACCOUNTED,
                self::OUTSTANDING,
            ))];
        }

        $rows = $this->rows();
        $stale = $this->staleness();
        $unaccounted = array_values(array_filter($rows, fn (array $row) => $row['disposition'] === self::UNACCOUNTED));

        if ($unaccounted === [] && $stale === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'Every registry-shaped class in this composition is accounted for (%s).',
                $this->tallyLine($rows),
            ))];
        }

        $findings = [];

        foreach ($unaccounted as $row) {
            $findings[] = Finding::warn(self::CHECK, sprintf(
                '[%s] %s is registry-shaped and declares no #[IsRegistry] — bound at %s:%d, and in no '.
                'disposition bucket. Run `%s` to record it as %s, or declare it.',
                self::UNACCOUNTED,
                $this->shortName($row['registry']),
                $row['file'],
                $row['line'],
                RegistryConformanceCommand::SIGNATURE,
                self::OUTSTANDING,
            ));
        }

        foreach ($stale as $finding) {
            $findings[] = Finding::warn(self::CHECK, $finding);
        }

        return $findings;
    }

    /**
     * Every registry-shaped, undeclared class in the composition with its disposition, plus every artifact
     * row whose subject the scan no longer produces (those carry an `expired` reason so the staleness pass can
     * name them).
     *
     * Sorted by FQCN so a re-run with no code change writes a byte-identical artifact.
     *
     * @return list<array{registry: string, provider: string|null, package: string|null, file: string|null, line: int|null, disposition: string, ticket: string|null, note: string|null, expired: string|null}>
     */
    public function rows(): array
    {
        $committed = $this->committedRows();
        $rows = [];

        foreach ($this->shape->undescribed() as $found) {
            $fqcn = $found['registry'];
            $row = $committed[$fqcn] ?? null;

            $rows[$fqcn] = [
                'registry' => $fqcn,
                'provider' => $found['provider'],
                'package' => $found['package'],
                'file' => $found['file'],
                'line' => $found['line'],
                'disposition' => $this->dispositionOf($fqcn, $row),
                'ticket' => $row['ticket'] ?? null,
                'note' => $this->whitelist[$fqcn] ?? ($row['note'] ?? null),
                'expired' => null,
            ];
        }

        // Artifact rows whose subject the scan no longer produces. Kept in the row set rather than dropped:
        // a row that quietly disappears is an exemption that expired without anyone reading it, and the
        // whole point of the staleness pass is that expiry gets SAID. `expired` records WHY the scan stopped
        // producing it, because the three reasons want three different sentences and only one of them
        // ("gone") means the name is now free for something else to take.
        foreach ($committed as $fqcn => $row) {
            $rows[$fqcn] ??= [
                'registry' => $fqcn,
                'provider' => null,
                'package' => null,
                'file' => null,
                'line' => null,
                'disposition' => (string) ($row['disposition'] ?? self::OUTSTANDING),
                'ticket' => $row['ticket'] ?? null,
                'note' => $row['note'] ?? null,
                'expired' => $this->expiryOf($fqcn),
            ];
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * The rows the artifact carries: everything except the whitelist, which lives in code, and except rows
     * whose subject is gone. `unaccounted` is PROMOTED to `outstanding` here — writing the artifact is what
     * accounts for a row, and a bucket that survives a write would make the count meaningless.
     *
     * @return list<array{registry: string, disposition: string, ticket: string|null, package: string|null, provider: string|null, note: string|null}>
     */
    public function artifactRows(): array
    {
        $rows = [];

        foreach ($this->rows() as $row) {
            if ($row['expired'] !== null || $row['disposition'] === self::WHITELISTED) {
                continue;
            }

            $rows[] = [
                'registry' => $row['registry'],
                'disposition' => $row['disposition'] === self::UNACCOUNTED ? self::OUTSTANDING : $row['disposition'],
                'ticket' => $row['ticket'],
                'package' => $row['package'],
                'provider' => $row['provider'],
                'note' => $row['note'],
            ];
        }

        return $rows;
    }

    /**
     * The three staleness findings, as finished sentences.
     *
     * @return list<string>
     */
    public function staleness(): array
    {
        $findings = [];
        $unanswerable = 0;

        foreach ($this->rows() as $row) {
            $fqcn = $row['registry'];

            if ($this->declares($fqcn)) {
                $findings[] = sprintf(
                    '[stale] %s is dispositioned `%s` but now %s. Drop the row; the gate (%s) governs it '.
                    'from here.',
                    $this->shortName($fqcn),
                    $row['disposition'],
                    $this->described($fqcn) ? 'describes itself into the registry index' : 'declares #[IsRegistry]',
                    RegistryConformanceAudit::CHECK,
                );

                continue;
            }

            if ($row['expired'] === self::EXPIRY_GONE) {
                $findings[] = sprintf(
                    '[stale] The artifact carries %s as `%s`, but no such class exists in this composition. '.
                    'Remove the row — an exemption that outlived its subject is permission for whatever '.
                    'takes the name next.',
                    $fqcn,
                    $row['disposition'],
                );

                continue;
            }

            if ($row['expired'] === self::EXPIRY_UNSHAPED) {
                $findings[] = sprintf(
                    '[stale] The artifact carries %s as `%s`, but the structural scan no longer produces it — '.
                    'it is not a registry-shaped singleton in this composition. Remove the row, or find out '.
                    'which of the two moved.',
                    $fqcn,
                    $row['disposition'],
                );

                continue;
            }

            if ($row['disposition'] !== self::DEFERRED) {
                continue;
            }

            if ($row['ticket'] === null || $row['ticket'] === '') {
                $findings[] = sprintf(
                    '[stale] %s is dispositioned `%s` but names no ticket. A deferral without a blocker is an '.
                    'exemption wearing a deadline.',
                    $this->shortName($fqcn),
                    self::DEFERRED,
                );

                continue;
            }

            $open = $this->ticketIsOpen((string) $row['ticket']);

            if ($open === null) {
                $unanswerable++;

                continue;
            }

            if ($open === false) {
                $findings[] = sprintf(
                    '[stale] %s is deferred against %s, which is CLOSED. The blocker is gone — migrate it, or '.
                    'give the row a live ticket.',
                    $this->shortName($fqcn),
                    $row['ticket'],
                );
            }
        }

        if ($unanswerable > 0) {
            $findings[] = sprintf(
                '[stale] %d deferred row(s) name a ticket this host cannot read, so their freshness is '.
                'UNCHECKED. Point `beam.core.registry_conformance.tracker_path` at the tracker root to close the '.
                'gap — an unanswerable question is reported rather than answered either way.',
                $unanswerable,
            );
        }

        return $findings;
    }

    /**
     * The four-way breakdown plus `unaccounted`. `conforming` is not this audit's to count — it is the
     * DECLARED population passing the gate — so it is filled by the caller that holds both audits.
     *
     * @param  list<array<string, mixed>>|null  $rows
     * @return array<string, int>
     */
    public function tally(?array $rows = null): array
    {
        $counts = [
            self::WHITELISTED => 0,
            self::DEFERRED => 0,
            self::OUTSTANDING => 0,
            self::UNACCOUNTED => 0,
        ];

        foreach ($rows ?? $this->rows() as $row) {
            if (($row['expired'] ?? null) !== null) {
                continue;
            }

            $disposition = (string) $row['disposition'];

            if (array_key_exists($disposition, $counts)) {
                $counts[$disposition]++;
            }
        }

        return $counts;
    }

    public function artifactPath(): string
    {
        return $this->artifactPath;
    }

    /**
     * @param  array<string, mixed>|null  $committed
     */
    protected function dispositionOf(string $fqcn, ?array $committed): string
    {
        if (array_key_exists($fqcn, $this->whitelist)) {
            return self::WHITELISTED;
        }

        $disposition = $committed['disposition'] ?? null;

        return in_array($disposition, [self::DEFERRED, self::OUTSTANDING], true)
            ? (string) $disposition
            : self::UNACCOUNTED;
    }

    /**
     * Rows of the committed artifact, keyed by FQCN. A missing or unreadable artifact is not an error: it is
     * the state before the first `--write`, and every row then reads as `unaccounted`, which is true.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function committedRows(): array
    {
        if (! is_file($this->artifactPath)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->artifactPath), true);
        $rows = is_array($decoded) && is_array($decoded['registries'] ?? null) ? $decoded['registries'] : [];
        $keyed = [];

        foreach ($rows as $row) {
            if (is_array($row) && is_string($row['registry'] ?? null)) {
                $keyed[$row['registry']] = $row;
            }
        }

        return $keyed;
    }

    /**
     * Why the scan no longer produces a committed row's subject. `null` is impossible here by construction —
     * this is only called for rows the scan did NOT produce — so the two cases are exhaustive.
     */
    protected function expiryOf(string $fqcn): string
    {
        return class_exists($fqcn) || interface_exists($fqcn) ? self::EXPIRY_UNSHAPED : self::EXPIRY_GONE;
    }

    /**
     * Whether the GATE governs this class — because a declaration reaches it, or because it is described
     * into the live index.
     *
     * The parent walk is {@see IsRegistry::declaredOn()}'s (registry-kernel ticket 42, landing 41 D11).
     * Without it this audit rowed every subclass of a declared registry as undeclared shape, which is the
     * opposite of true: the subclass runs under a declaration, it just does not restate one. Beam-core
     * ships subclassing as its extension mechanism, so the rows would have been permanent and unfixable —
     * an advisory backlog whose only remedy was to stop extending.
     *
     * The index arm is registry-kernel 26 D5, landed by 49: **described IS the population**, so a class
     * that describes itself with a declaration passed at runtime — no class attribute anywhere — is
     * governed by {@see RegistryConformanceAudit} all the same. This must ask exactly the question that
     * audit's `population()` answers; if it asks a narrower one, a row here sits `outstanding` forever
     * while the gate is already governing its subject, and the staleness pass reports the wrong reason.
     */
    protected function declares(string $fqcn): bool
    {
        if (! class_exists($fqcn) && ! interface_exists($fqcn)) {
            return false;
        }

        try {
            if (IsRegistry::declaredOn($fqcn) !== null) {
                return true;
            }
        } catch (\Throwable) {
            // A class that will not reflect is a miss, not a fatal — the index arm below may still answer.
        }

        return $this->described($fqcn);
    }

    /**
     * Whether this class owns a root in the live index.
     */
    protected function described(string $fqcn): bool
    {
        $index = $this->shape->index();

        foreach ($index->unfiltered()->keys() as $key) {
            $owner = $index->owner($key);

            if ($owner !== null && $owner::class === $fqcn) {
                return true;
            }
        }

        return false;
    }

    /** True open, false closed, null unanswerable. */
    protected function ticketIsOpen(string $ticket): ?bool
    {
        if (! is_callable($this->ticketStatus)) {
            return null;
        }

        $answer = ($this->ticketStatus)($ticket);

        return is_bool($answer) ? $answer : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function tallyLine(array $rows): string
    {
        $parts = [];

        foreach ($this->tally($rows) as $disposition => $count) {
            $parts[] = $disposition.' '.$count;
        }

        return implode(', ', $parts);
    }

    protected function shortName(string $fqn): string
    {
        $pos = strrpos($fqn, '\\');

        return $pos === false ? $fqn : substr($fqn, $pos + 1);
    }
}
