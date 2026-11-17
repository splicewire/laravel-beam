<?php

namespace Splicewire\Beam\Localization\Client;

/**
 * The ONE reader of the host's discovered client catalog (localization 16; ruling 02 §3): the version-1 artifact that
 * `schemastud-messages collect --out resources/beam/messages.json --write` writes.
 *
 *     {"payload": {"version": 1, "packages": [{name, namespace, sharesWith, catalogDigest}], "messages": {key: {default, owner}},
 *                  "notes"?: {key: note}},
 *      "digest": "sha256:<hex of JCS(payload)>"}
 *
 * It admits the file through {@see Jcs::parse()}, re-verifies the digest with the same JCS, and checks the shape the
 * collector writes. Package keys are `<namespace>::<item>` with a declared namespace; host keys are unnamespaced; item
 * grammar and reserved groups are the collector's own. It reports an explicit state: VALID, MISSING (no file) or INVALID
 * (with the reason). A missing or invalid catalog is never an empty success; the doctor, tests and export report it.
 *
 * Since schemastud/messages 67 a payload may carry translator notes (frame-foundation 69): `notes` sits beside `messages`,
 * written only when a note exists, each a non-empty string of at most 500 characters for a key in `messages`. A payload
 * without `notes` reads exactly as before.
 */
final class DiscoveredCatalog
{
    /** The collector's reserved derived groups (messages/bin/collect.mjs validItem), mirrored for admission parity. */
    // The derived (beam-reserved) first groups; the same list as schemastud's collect.mjs (47810c9e added realms).
    private const RESERVED_GROUPS = ['resources', 'data', 'enums', 'actions', 'nav', 'groups', 'realms'];

    /** The collector's note cap (messages/bin/collect.mjs catalogNotes), mirrored for admission parity. */
    private const NOTE_MAX_LENGTH = 500;

    /**
     * @param  list<array{name: string, namespace: string, sharesWith: ?string, catalogDigest: string}>  $packages
     * @param  array<string, array{default: string, owner: string}>  $defaults
     * @param  array<string, string>  $notes  translator notes, key => note (a subset of the defaults' keys)
     */
    private function __construct(
        public readonly string $path,
        public readonly DiscoveryState $state,
        public readonly ?string $problem = null,
        public readonly ?string $payloadDigest = null,
        public readonly array $packages = [],
        public readonly array $defaults = [],
        public readonly array $notes = [],
    ) {}

    public static function read(string $path): self
    {
        if (! is_file($path)) {
            return new self($path, DiscoveryState::Missing, "No discovered client catalog at {$path}: run `schemastud-messages collect --out {$path} --write`.");
        }
        try {
            return self::admit($path, Jcs::parse((string) file_get_contents($path)));
        } catch (InvalidClientCatalog $e) {
            return new self($path, DiscoveryState::Invalid, $e->getMessage());
        }
    }

    public function valid(): bool
    {
        return $this->state === DiscoveryState::Valid;
    }

    /** The translator note for a key, or null when it has none. */
    public function note(string $key): ?string
    {
        return $this->notes[$key] ?? null;
    }

    /** @return list<string> the package namespaces it declares */
    public function namespaces(): array
    {
        return array_values(array_unique(array_column($this->packages, 'namespace')));
    }

    private static function admit(string $path, mixed $artifact): self
    {
        $fail = static fn (string $why): never => throw new InvalidClientCatalog($why);
        if (! $artifact instanceof JsonObject || array_keys($artifact->members()) !== ['payload', 'digest'] && array_keys($artifact->members()) !== ['digest', 'payload']) {
            $fail('The artifact must be exactly {payload, digest}.');
        }
        $payload = $artifact['payload'];
        $digest = $artifact['digest'];
        if (! $payload instanceof JsonObject || ! is_string($digest) || preg_match('/^sha256:[0-9a-f]{64}$/', $digest) !== 1) {
            $fail('The artifact has no payload object or no sha256 digest.');
        }
        if (! hash_equals($digest, 'sha256:'.Jcs::hash($payload))) {
            $fail('The artifact digest does not match JCS(payload): it was edited by hand or written by another tool.');
        }
        if ($payload['version'] !== 1 || ! is_array($payload['packages']) || ! $payload['messages'] instanceof JsonObject) {
            $fail('The payload is not version 1 with packages and messages.');
        }

        $packages = [];
        $namespaces = [];
        foreach ($payload['packages'] as $i => $package) {
            $name = $package['name'] ?? null;
            $namespace = $package['namespace'] ?? null;
            $shares = $package instanceof JsonObject && $package->offsetExists('sharesWith') ? $package['sharesWith'] : false;
            $catalogDigest = $package['catalogDigest'] ?? null;
            if (! $package instanceof JsonObject || ! is_string($name) || ! is_string($namespace) || preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $namespace) !== 1
                || ! ($shares === null || (is_string($shares) && preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $shares) === 1))
                || ! is_string($catalogDigest) || preg_match('/^sha256:[0-9a-f]{64}$/', $catalogDigest) !== 1) {
                $fail("Package #{$i} is not {name, namespace, sharesWith, catalogDigest}.");
            }
            $owner = $shares ?? $name;
            if (isset($namespaces[$namespace]) && $namespaces[$namespace] !== $owner) {
                $fail("Namespace [{$namespace}] has conflicting owners.");
            }
            $namespaces[$namespace] = $owner;
            $packages[] = ['name' => $name, 'namespace' => $namespace, 'sharesWith' => $shares, 'catalogDigest' => $catalogDigest];
        }

        $defaults = [];
        foreach ($payload['messages'] as $key => $entry) {
            $key = (string) $key;
            if (! $entry instanceof JsonObject || ! is_string($entry['default'] ?? null) || ! is_string($entry['owner'] ?? null) || count($entry) !== 2) {
                $fail("Message [{$key}] is not {default, owner}.");
            }
            $namespace = str_contains($key, '::') ? substr($key, 0, (int) strpos($key, '::')) : null;
            $item = $namespace === null ? $key : substr($key, strlen($namespace) + 2);
            if (($namespace !== null && ! isset($namespaces[$namespace])) || ! self::validItem($item)) {
                $fail("Message key [{$key}] names no declared namespace or breaks the key grammar.");
            }
            $defaults[$key] = ['default' => $entry['default'], 'owner' => $entry['owner']];
        }

        $notes = [];
        if ($payload->offsetExists('notes')) {
            if (! $payload['notes'] instanceof JsonObject) {
                $fail('The payload notes are not an object of key => note.');
            }
            foreach ($payload['notes'] as $key => $note) {
                $key = (string) $key;
                if (! isset($defaults[$key])) {
                    $fail("Note [{$key}] names no message.");
                }
                if (! is_string($note) || trim($note) === '' || self::utf16Length($note) > self::NOTE_MAX_LENGTH) {
                    $fail("Note [{$key}] is not a non-empty string of at most ".self::NOTE_MAX_LENGTH.' characters.');
                }
                $notes[$key] = $note;
            }
        }

        return new self($path, DiscoveryState::Valid, null, $digest, $packages, $defaults, $notes);
    }

    /** A note's length as the collector measures it: JavaScript string length, in UTF-16 code units. */
    private static function utf16Length(string $text): int
    {
        return intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    private static function validItem(string $item): bool
    {
        return preg_match('#^[A-Za-z0-9_:/-]+(?:\.[A-Za-z0-9_:/-]+)+$#', $item) === 1
            && ! in_array(explode('.', $item)[0], self::RESERVED_GROUPS, true);
    }
}
