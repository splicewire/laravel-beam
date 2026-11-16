<?php

namespace Splicewire\Beam\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Config\ConfigSectionCoverage;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Splicewire\Beam\Config\Overrides\ConfigOverlay;
use Splicewire\Beam\Config\RegisteredConfigSection;
use Throwable;

/**
 * **Every key under a declared root is described** (config-sections ticket 06). For each registered Config Section:
 *
 * - every key in the root's merged values is covered by the section, a nested class or map entry, or a
 *   `#[ConfigOpaque]` key ({@see ConfigSectionCoverage::uncovered()}); an uncovered key is one a Config area cannot
 *   show, type or validate, and an operator write to it is refused for want of a declaration;
 * - the package defaults (the registered package file) validate against the section's schema, so the section and the
 *   file it describes agree;
 * - every key has a description that is not the generator's harvested draft (`#[ConfigDraft]`).
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

        return $findings !== [] ? $findings : [Finding::pass(self::CHECK, sprintf('%d Config Section(s): every merged key is covered, the package defaults validate, and every key has a description.', count($sections)))];
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
