<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\Support\FacadeConformanceScope;
use Splicewire\Beam\Doctor\Support\FacadeReferenceScanner;

/**
 * **No `.php.stub` migration template may name the deleted static bridge** `Splicewire\Beam\Beam`
 * (beam-facade tickets 10 §6 and 19).
 *
 * ## Why this one is owed rather than chosen
 * It is the durability half of ticket 07's option **B3**. 07 chose 39 hand edits over B1 (teaching
 * surgeon the `.php.stub` extension upstream), and ticket 03 had named **durability, not effort**, as
 * B3's specific weakness: nothing prevents the 40th stub. B3 answers that objection only by pairing the
 * hand edits with a standing check, which is why ticket 10 could not be ruled out of scope — declining
 * it would have retroactively reduced B3 to "39 hand edits and nothing stopping the next one" and
 * reopened a closed ruling.
 *
 * ## Why it is not hypothetical
 * The publish-lag window was measured, twice, while this very map was being worked:
 * - ticket 15 swept the 39 stubs and found one **brand-new stub born importing the bridge**, authored by
 *   a concurrent session between the census and the sweep;
 * - ticket 16 found the same failure 22× larger on the other side of the publish boundary —
 *   `~/Herd/splicewire-app/database/migrations/shared/` held 22 host migrations published on 2026-08-13
 *   from stubs that 15 swept on 2026-08-14. Publish output born importing the bridge.
 *
 * The estate demonstrably publishes faster than it sweeps, and the gap opened and closed inside a single
 * 24-hour window. Note the asymmetry that follows: fixing every `.stub` does nothing for a host that has
 * already published, and a published migration is a divergent fork upstream never reaches — which is why
 * the published copies are excluded here (advising a hand-edit of generated output is the wrong
 * nomination) and why the stub, the authored origin, is the only place this check looks.
 *
 * ## Substrate
 * Doctor, not surgeon, for a structural reason rather than a preference: surgeon hardcodes the `php`
 * extension in `AuditEngine::phpFilesIn()` and is **blind to `.php.stub`** — the very population this
 * check is about. A plain {@see DoctorAudit} may glob whatever it likes.
 *
 * Comment-aware via {@see FacadeReferenceScanner}: an import or a static call is drift, a `{@see}` or
 * `@method` tag pointing at the deleted class is drift, and loose prose naming `Beam::table()` is not —
 * 07 ruled prose stays, since the call syntax never changed.
 *
 * Advisory ({@see \Splicewire\Beam\BeamServiceProvider::registerFacadeConformanceAudits()}).
 */
class StubStaticReferenceAudit implements DoctorAudit
{
    public const CHECK = 'beam.facade.stub-static-reference';

    public function __construct(protected FacadeConformanceScope $scope) {}

    public static function forApp(?FacadeConformanceScope $scope = null): self
    {
        return new self($scope ?? FacadeConformanceScope::forApp());
    }

    /**
     * @return list<Finding>
     */
    public function run(): array
    {
        $rows = $this->references();

        if ($rows === []) {
            return [Finding::pass(self::CHECK, sprintf(
                'No .php.stub migration template names the deleted static bridge %s (%d stub(s) scanned).',
                FacadeConformanceScope::BRIDGE_CLASS,
                count($this->stubs()),
            ))];
        }

        return array_map(fn (array $row): Finding => Finding::warn(self::CHECK, sprintf(
            '%s:%d names %s, which ticket 18 deleted — a host publishing this stub gets a migration that '.
            'fatals on a missing class. Import %s instead; the `Beam::` call syntax is unchanged.',
            $row['file'],
            $row['line'],
            FacadeConformanceScope::BRIDGE_CLASS,
            FacadeConformanceScope::FACADE_CLASS,
        )), $rows);
    }

    /**
     * Every stub reference to the bridge, as sorted rows — the work-list.
     *
     * @return list<array{file: string, line: int, kind: string}>
     */
    public function references(): array
    {
        $rows = [];

        foreach ($this->scope->sourcesContaining([FacadeConformanceScope::BRIDGE_CLASS]) as $path => $source) {
            if (! str_ends_with($path, '.php.stub')) {
                continue;
            }

            foreach ($this->referencesInSource($source) as $row) {
                $rows[] = ['file' => FacadeConformanceScope::displayPath($path)] + $row;
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return $rows;
    }

    /**
     * Parse ONE stub. Pure over source — unit-callable with no disk, container, or DB.
     *
     * @return list<array{line: int, kind: string}>
     */
    public function referencesInSource(string $source): array
    {
        $rows = [];

        foreach (FacadeReferenceScanner::codeReferences($source, FacadeConformanceScope::BRIDGE_CLASS) as $line) {
            $rows[] = ['line' => $line, 'kind' => 'code'];
        }

        foreach (FacadeReferenceScanner::docTagReferences($source, FacadeConformanceScope::BRIDGE_CLASS) as $line) {
            $rows[] = ['line' => $line, 'kind' => 'doc-tag'];
        }

        usort($rows, fn (array $a, array $b): int => $a['line'] <=> $b['line']);

        return $rows;
    }

    /**
     * The governed population — every `.php.stub` in scope. Reported in the Pass line so a green run
     * states what it covered: "no findings" and "found nothing to look at" are different facts, and a
     * scope that silently collapsed to zero roots would otherwise read as conformance.
     *
     * @return list<string>
     */
    public function stubs(): array
    {
        return array_values(array_filter(
            $this->scope->files(),
            static fn (string $file): bool => str_ends_with($file, '.php.stub'),
        ));
    }
}
