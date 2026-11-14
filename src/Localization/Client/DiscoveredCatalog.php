<?php

namespace Splicewire\Beam\Localization\Client;

/**
 * The ONE reader of the host's discovered client catalog (localization 16; ruling 02 §3): the version-1 artifact that
 * `schemastud-messages collect --out resources/beam/messages.json --write` writes.
 *
 *     {"payload": {"version": 1, "packages": [{name, namespace, sharesWith, catalogDigest}], "messages": {key: {default, owner}}},
 *      "digest": "sha256:<hex of JCS(payload)>"}
 *
 * It admits the file through {@see Jcs::parse()}, re-verifies the digest with the same JCS, and checks the shape the
 * collector writes. Package keys are `<namespace>::<item>` with a declared namespace; host keys are unnamespaced; item
 * grammar and reserved groups are the collector's own. It reports an explicit state: VALID, MISSING (no file) or INVALID
 * (with the reason). A missing or invalid catalog is never an empty success; the doctor, tests and export report it.
 */
final class DiscoveredCatalog
{
    /** The collector's reserved derived groups (messages/bin/collect.mjs validItem), mirrored for admission parity. */
    // The derived (beam-reserved) first groups; the same list as schemastud's collect.mjs (47810c9e added realms).
    private const RESERVED_GROUPS = ['resources', 'data', 'enums', 'actions', 'nav', 'groups', 'realms'];

    /**
     * @param  list<array{name: string, namespace: string, sharesWith: ?string, catalogDigest: string}>  $packages
     * @param  array<string, array{default: string, owner: string}>  $defaults
     */
    private function __construct(
        public readonly string $path,
        public readonly DiscoveryState $state,
        public readonly ?string $problem = null,
        public readonly ?string $payloadDigest = null,
        public readonly array $packages = [],
        public readonly array $defaults = [],
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

        return new self($path, DiscoveryState::Valid, null, $digest, $packages, $defaults);
    }

    private static function validItem(string $item): bool
    {
        return preg_match('#^[A-Za-z0-9_:/-]+(?:\.[A-Za-z0-9_:/-]+)+$#', $item) === 1
            && ! in_array(explode('.', $item)[0], self::RESERVED_GROUPS, true);
    }
}
