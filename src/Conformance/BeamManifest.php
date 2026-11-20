<?php

namespace Splicewire\Beam\Conformance;

use Symfony\Component\Yaml\Yaml;

/**
 * The Beam Manifest — a site's self-description, read from BEAM.md at its repo root. It records
 * divergence from the Base recipe only (what it adopts, its intentional Deviations, and
 * detected-but-unreconciled Deltas); anything that simply conforms is left unsaid. See
 * references/conformance.md in the runbook for the schema.
 *
 * Relocated from splicewire/laravel-satellite into beam core (browser-journeys 07): the flow helper
 * and the doctor's catalog join both live in beam and must read Deviations, and beam is the one
 * package every flow owner already requires — a reader left in satellite is unreachable for them.
 * Satellite keeps a deprecated `class_alias` forwarder until a contract ticket proves zero callers.
 *
 * Filename rationale: the manifest describes the BEAM shell — what every site is, generation aside —
 * so it takes the beam product word, matching the config-key convention (`beam-accounts`,
 * `beam-multi-tenancy`, …). The `BEAM.md` name is the user ruling of 2026-07-26 (relocation issue 03),
 * which derives it from that product-word rule; ADR-0092 keeps the product word "beam" but decides the
 * vendor move, not the filename. `SPLICEWIRE.md` is deliberately RESERVED for the future engine-level
 * scenario (a beam site implementing splicewire-the-engine), not spent as the base manifest name. The
 * pre-rename filename is no longer read (expand → contract, done).
 *
 * This is a pure reader: a missing or empty manifest yields an empty instance whose deviates() is
 * always false. It is the doctor's manifest check — not this class — that FAILs on absence.
 */
class BeamManifest
{
    /** The current manifest filename — the beam product word (the manifest describes the beam shell). */
    public const FILENAME = 'BEAM.md';

    /** @param array<string, mixed> $data parsed YAML front matter */
    public function __construct(private array $data) {}

    /**
     * Load the manifest for a site. With no argument, resolves BEAM.md at base_path(); with an
     * explicit file path, reads that path directly.
     */
    public static function load(?string $path = null): self
    {
        $path ??= self::resolvePath(base_path());

        if (! is_file($path)) {
            return new self([]);
        }

        return self::parse((string) file_get_contents($path));
    }

    /**
     * Resolve the manifest file inside $dir — always the BEAM.md path, whether or not it exists,
     * so a missing-file check/message names the convention.
     */
    public static function resolvePath(string $dir): string
    {
        return rtrim($dir, '/').'/'.self::FILENAME;
    }

    public static function parse(string $contents): self
    {
        $frontMatter = self::extractFrontMatter($contents);

        if ($frontMatter === null) {
            return new self([]);
        }

        $data = Yaml::parse($frontMatter);

        return new self(is_array($data) ? $data : []);
    }

    /** Pull the leading `---`-fenced YAML block; null when the file has no front matter. */
    private static function extractFrontMatter(string $contents): ?string
    {
        if (! preg_match('/\A\x{FEFF}?\s*---\R(.*?)\R---\s*(?:\R|\z)/us', $contents, $m)) {
            return null;
        }

        return $m[1];
    }

    public function satellite(): ?string
    {
        return $this->data['satellite'] ?? null;
    }

    public function variant(): ?string
    {
        return $this->data['variant'] ?? null;
    }

    /** @return list<string> */
    public function adopts(): array
    {
        return array_values((array) ($this->data['adopts'] ?? []));
    }

    /**
     * The declared Deviations, each a raw map. A Deviation entry may carry an optional `package:`
     * (browser-journeys 02 R3-Q3) naming the package that contributes the deviated key; it is parsed
     * and preserved here for the doctor to read, so a host can tell a typo from a removed package's
     * flow after a `composer remove` forgets the registrant.
     *
     * @return list<array<string, mixed>>
     */
    public function deviations(): array
    {
        return array_values(array_filter((array) ($this->data['deviations'] ?? []), 'is_array'));
    }

    /** @return list<array<string, mixed>> */
    public function deltas(): array
    {
        return array_values(array_filter((array) ($this->data['deltas'] ?? []), 'is_array'));
    }

    /**
     * Does this site declare a Deviation covering `surface.flow` (e.g. `billing.gifting`,
     * `account.security.passkeys`)?
     *
     * The key is split on its first dot into a `branch` and the remaining `flow` (which may itself
     * hold dots). A Deviation matches when it names that branch and either:
     *  - omits the whole branch (`status: omitted`, or no status), covering every flow under it; or
     *  - is `partial` and one of its `flows:` entries COVERS the queried flow by whole segment — an
     *    entry `security` covers `security` and every descendant (`security.login`,
     *    `security.login.totp`), but not a sibling segment (`securityx`) and not a shorter stub
     *    (`sec` covers nothing).
     *
     * Compatibility rule: a bare `surface` queried with no dot matches any Deviation on that branch,
     * omitted or partial. Shipped browser tests call this to `->skip()` declared Deviations rather
     * than fail them.
     */
    public function deviates(string $surfaceFlow): bool
    {
        [$branch, $flow] = array_pad(explode('.', $surfaceFlow, 2), 2, null);

        foreach ($this->deviations() as $deviation) {
            if (($deviation['branch'] ?? null) !== $branch) {
                continue;
            }

            $status = $deviation['status'] ?? 'omitted';

            if ($status === 'omitted') {
                return true;
            }

            // partial: the branch is installed; only the named flows (and their descendants) are off.
            if ($flow === null) {
                // Bare-surface compatibility rule: any Deviation on the branch matches.
                return true;
            }

            foreach (array_values((array) ($deviation['flows'] ?? [])) as $declared) {
                if (self::flowCovers((string) $declared, $flow)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whole-segment descendant coverage: a declared flow key covers itself and every descendant,
     * matched segment by segment. `security` covers `security` and `security.login`; it does not
     * cover `securityx` (a different segment) or answer to a shorter stub (`sec`). A declared key may
     * itself hold dots (`security.login` covers `security.login.totp`).
     */
    private static function flowCovers(string $declared, string $queried): bool
    {
        return $declared === $queried || str_starts_with($queried, $declared.'.');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
