<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Config\ConfigSectionCoverage;
use Splicewire\Beam\Config\ConfigSectionDeclarations;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\RegisteredConfigSection;
use Splicewire\Beam\Doctor\Support\ConfigRootCensus;
use Throwable;

/**
 * **Every key under a declared root is described** (config-sections ticket 06). For each registered Config Section:
 *
 * - every key in the root's merged values is covered by the section, a nested class or map entry, or a
 *   `#[ConfigOpaque]` key ({@see ConfigSectionCoverage::uncovered()}); an uncovered key is one a Config area cannot
 *   show, type or validate, and an operator write to it is refused for want of a declaration;
 * - the package defaults (the registered package file) validate against the section's schema, so the section and the
 *   file it describes agree;
 * - every key has a description that is not the generator's harvested draft (`#[ConfigDraft]`);
 * - the section declares nothing the ladder cannot honour ({@see ConfigSectionDeclarations}): no explicit
 *   `secret: false` below a secret, no secret inside a list of records (a failure), no secret open to the operator tier
 *   without a `closed:` either way, and no secret the declaration walk cannot reach.
 *
 * The values read are the package and host tiers, without the operator's published layer
 * ({@see ConfigOverlay::base()}). Report-only (advisory); ticket 13 turns it into a gate.
 */
class ConfigSectionCoverageAudit implements DoctorAudit
{
    public const CHECK = 'config.section-coverage';

    private const SHOWN = 5;

    public function __construct(
        protected ConfigSectionRegistry $sections,
    ) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $findings = [];
        $sections = $this->sections->all();

        foreach ($sections as $registered) {
            array_push($findings, ...$this->section($registered));
        }

        if ($sections !== [] && $findings === []) {
            $findings[] = Finding::pass(self::CHECK, sprintf('%d Config Section(s): every merged key is covered, the package defaults validate, and every key has a description.', count($sections)));
        }

        // config-sections ticket 13, Part 3 (report-only under clamp (a)): classify who owns each config root and list
        // the roots that are unowned by design, so ticket 13's coverage gate has a vetted skip-set. Classifies and
        // lists only; gates nothing here.
        $findings[] = $this->ownership();

        return $findings;
    }

    /** The config-root ownership census as one report-only finding (config-sections ticket 13, Part 3). */
    protected function ownership(): Finding
    {
        try {
            $census = $this->census();
            $roots = $census->roots();
            $unowned = $census->unownedByDesign();

            $families = array_count_values(array_map(static fn (array $entry): string => $entry['family'], $roots));

            $breakdown = implode(', ', array_map(
                static fn (string $family, int $count): string => "{$count} {$family}",
                array_keys($families),
                array_values($families),
            ));

            // Each unowned-by-design root by NAME, with its package (owner) and family (LANE-B-2365 item 2).
            $unownedList = $unowned === []
                ? 'none'
                : implode('; ', array_map(static fn (array $e): string => sprintf('%s (package %s, family %s, by %s)', $e['root'], $e['owner'], $e['family'], $e['source']), $unowned));

            return Finding::pass(self::CHECK, sprintf(
                'Config root ownership (report-only): %d root(s)%s. Owner source: the registry for a declared root, the config/ file tree otherwise; family from the owning package declared requires. Unowned by design (no Config Section expected, a coverage gate must pass these): %s.',
                count($roots),
                $breakdown === '' ? '' : ' — '.$breakdown,
                $unownedList,
            ));
        } catch (Throwable $e) {
            // A census that could not be taken or classified is INDETERMINATE, not a pass: a status-based consumer or a
            // future coverage gate must not read a failed census as a valid empty skip-set (LANE-B-2370 MEDIUM).
            return Finding::warn(self::CHECK, sprintf('Config root ownership census not taken: %s', $e->getMessage()));
        }
    }

    /** The census, as a seam so a test can substitute one that throws and assert the indeterminate path. */
    protected function census(): ConfigRootCensus
    {
        return new ConfigRootCensus($this->sections);
    }

    /** @return list<Finding> */
    protected function section(RegisteredConfigSection $registered): array
    {
        $root = $registered->root();
        $findings = [];

        try {
            $values = app(ConfigOverlay::class)->base($root);
            $uncovered = ConfigSectionCoverage::uncovered($registered->section, is_array($values) ? $values : []);

            if ($uncovered !== []) {
                $findings[] = Finding::warn(self::CHECK, sprintf(
                    '%s: %d key(s) in config() are covered by no section key and no #[ConfigOpaque]: %s',
                    $root,
                    count($uncovered),
                    $this->listed($uncovered),
                ));
            }

            $defaults = $this->packageDefaults($registered);

            if ($defaults !== null && ! app(AcceptanceGate::class)->accepts($defaults, app(ConfigSectionSchema::class)->for($registered->section))) {
                $findings[] = Finding::fail(self::CHECK, sprintf(
                    '%s: the package defaults in %s do not validate against %s, so the section misdescribes its own file.',
                    $root,
                    $registered->packageFile,
                    $registered->section,
                ));
            }

            $drafts = ConfigSectionCoverage::drafts($registered->section);

            if ($drafts !== []) {
                $findings[] = Finding::warn(self::CHECK, sprintf(
                    '%s: %d key(s) have no description, or still the harvested draft (#[ConfigDraft]): %s',
                    $root,
                    count($drafts),
                    $this->listed($drafts),
                ));
            }

            // config-sections 22, 21, 17: what the section DECLARES that the ladder cannot honour, or honours silently. A
            // secret inside a list of records fails (no tier can address one record's secret, and a re-save that does not
            // resend it loses it); the rest warn.
            foreach (ConfigSectionDeclarations::findings($registered->section) as $finding) {
                $findings[] = $finding['kind'] === ConfigSectionDeclarations::KIND_SECRET_IN_LIST
                    ? Finding::fail(self::CHECK, "{$root}: {$finding['message']}")
                    : Finding::warn(self::CHECK, "{$root}: {$finding['message']}");
            }
        } catch (Throwable $e) {
            $findings[] = Finding::warn(self::CHECK, sprintf('%s: the section could not be checked: %s', $root, $e->getMessage()));
        }

        return $findings;
    }

    /** @return array<array-key, mixed>|null */
    protected function packageDefaults(RegisteredConfigSection $registered): ?array
    {
        if ($registered->packageFile === null || ! is_file($registered->packageFile)) {
            return null;
        }

        $values = (static fn (string $path): mixed => require $path)($registered->packageFile);

        return is_array($values) ? $values : null;
    }

    /** @param list<string> $pointers */
    private function listed(array $pointers): string
    {
        $shown = implode(', ', array_slice($pointers, 0, self::SHOWN));

        return count($pointers) > self::SHOWN ? $shown.sprintf(' (+%d more)', count($pointers) - self::SHOWN) : $shown;
    }
}
