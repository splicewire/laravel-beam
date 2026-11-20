<?php

namespace Splicewire\Beam\Testing;

use InvalidArgumentException;
use Splicewire\Beam\Flows\FlowDeclaration;

/**
 * The pure logic behind the shipped browser-test flow helper (browser-journeys 10): the screenshot-name
 * scheme and the key/role validation. Kept free of the test runtime so it is unit-testable in beam's own
 * suite; the global {@see flow()} helper (in flow-helper.php) wires this to the running Pest test.
 */
final class Flow
{
    /**
     * The committed-baseline screenshot basename (no extension): `<flow key>[--<role>]@<width>`.
     *
     * The key's dots are KEPT, not folded to dashes — PRD "Committed screenshot baselines" (finding 4):
     * dots-to-dashes collides, e.g. `account.security.passkeys` and `account.security-passkeys` would both
     * become `account-security-passkeys`. Keeping the dots, and because `--` is forbidden in a key segment,
     * `<key>--<role>` is unambiguous. The fixed viewport width is appended so one flow's baselines at two
     * widths never overwrite each other.
     */
    public static function screenshotName(string $key, ?string $role, int $width): string
    {
        self::assertValidKey($key);

        if ($role !== null) {
            self::assertSegment($role, "role '{$role}'");
        }

        if ($width <= 0) {
            throw new InvalidArgumentException("screenshot width must be a positive integer, got {$width}.");
        }

        return $key.($role !== null ? "--{$role}" : '')."@{$width}";
    }

    /**
     * Each dot-separated segment of a flow key is `[a-z][a-z0-9]*(-[a-z0-9]+)*`: a leading letter, then
     * lowercase letters/digits joined by single dashes. No `--`, no dot within a segment, no upper-case.
     */
    public static function assertValidKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('flow key is empty.');
        }

        foreach (explode('.', $key) as $segment) {
            self::assertSegment($segment, "flow key '{$key}' segment '{$segment}'");
        }
    }

    private static function assertSegment(string $segment, string $what): void
    {
        if (preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/', $segment) !== 1) {
            throw new InvalidArgumentException(
                "{$what} is invalid: use lowercase letters and digits joined by single '-' (no '--', no '.', no "
                .'upper-case; a segment starts with a letter).'
            );
        }
    }

    /**
     * Refuse a role the flow's declaration does not list (browser-journeys 10). A flow with no declared
     * roles (`[]`) is a guest flow: it takes `null`, never a role.
     */
    public static function assertRoleDeclared(FlowDeclaration $declaration, ?string $role): void
    {
        if ($role === null) {
            return;
        }

        if (! in_array($role, $declaration->roles, true)) {
            throw new InvalidArgumentException(
                "role '{$role}' is not declared for flow '{$declaration->key}'".
                ($declaration->roles === []
                    ? ' (a guest flow declares no roles).'
                    : ' (declared: '.implode(', ', $declaration->roles).').')
            );
        }
    }
}
