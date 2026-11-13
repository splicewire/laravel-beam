<?php

namespace Splicewire\Beam\Localization\Ratchet;

use JsonException;

/**
 * A package's reviewed ratchet exclusions (localization 12's follow-up; coord-lead ruling 22:26Z): text the census sees
 * that must stay as it is, each with a REASON. `lang-ratchet-exclusions.json` at the package root, hand-maintained and
 * reviewed like code (the ratchet never writes it):
 *
 *     {"exclusions": [
 *       {"file": "src/Ops/ReplayNotificationStatusOp.php", "method": "assertReplayable",
 *        "reason": "replay diagnostics stay raw (ruling 04)"}
 *     ]}
 *
 * An entry names a `file` plus a `method` (every hit in it) or a `line` (a hit on it), and a non-empty `reason`. An
 * excluded hit is not counted but is reported with its reason, never silently dropped; an entry that matches no hit is
 * STALE and fails the compare, so the list cannot outlive what it excuses. A malformed entry is refused by name.
 */
final class TextRatchetExclusions
{
    public const FILE = 'lang-ratchet-exclusions.json';

    /**
     * @param  list<array{file: string, method?: string, line?: int, reason: string}>  $entries
     * @param  list<string>  $errors  malformed entries, by position
     */
    private function __construct(
        public readonly array $entries,
        public readonly array $errors,
    ) {}

    public static function load(string $root): self
    {
        $path = rtrim($root, '/').'/'.self::FILE;
        if (! is_file($path)) {
            return new self([], []);
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new self([], [self::FILE.' is not valid JSON: '.$e->getMessage()]);
        }

        $entries = [];
        $errors = [];
        foreach ((array) ($data['exclusions'] ?? []) as $i => $entry) {
            $file = is_array($entry) ? ($entry['file'] ?? null) : null;
            $method = is_array($entry) ? ($entry['method'] ?? null) : null;
            $line = is_array($entry) ? ($entry['line'] ?? null) : null;
            $reason = is_array($entry) ? ($entry['reason'] ?? null) : null;
            if (! is_string($file) || $file === '' || ! is_string($reason) || trim($reason) === ''
                || ($method === null) === ($line === null) || ($method !== null && ! is_string($method)) || ($line !== null && ! is_int($line))) {
                $errors[] = "exclusion #{$i} needs a file, exactly one of method or line, and a reason";

                continue;
            }
            $entries[] = array_filter(['file' => $file, 'method' => $method, 'line' => $line, 'reason' => $reason], static fn ($v): bool => $v !== null);
        }

        return new self($entries, $errors);
    }

    /**
     * The entry that excludes a hit, or null.
     *
     * @param  array{file: string, line: int, method: ?string, kind: string}  $hit
     * @return array{file: string, method?: string, line?: int, reason: string}|null
     */
    public function match(array $hit): ?array
    {
        foreach ($this->entries as $entry) {
            if ($entry['file'] !== $hit['file']) {
                continue;
            }
            if ((isset($entry['method']) && $entry['method'] === $hit['method']) || (isset($entry['line']) && $entry['line'] === $hit['line'])) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The entries no hit matches.
     *
     * @param  list<array{file: string, line: int, method: ?string, kind: string}>  $hits
     * @return list<array{file: string, method?: string, line?: int, reason: string}>
     */
    public function stale(array $hits): array
    {
        return array_values(array_filter($this->entries, function (array $entry) use ($hits): bool {
            foreach ($hits as $hit) {
                if ($this->match($hit) === $entry) {
                    return false;
                }
            }

            return true;
        }));
    }
}
