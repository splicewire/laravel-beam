<?php

namespace Splicewire\Beam\Ia;

/**
 * The host's realm profile: ux-walkthrough M1, `beam.core.realms.{key}` = `{label, home, surface, gate}`.
 *
 * `home` is a ROUTE NAME, `surface` is `site` or `app`, and `gate` is `{entitlement, mode, upsell?}`. A host declares
 * only what differs from the kit defaults below. The profile subsumes `beam.core.realm_gates`, which stays readable
 * as a deprecated alias: a profile `gate` wins, and an alias entry gates a realm whose profile declares none.
 *
 * `beam.core.realms.classes` predates the profile (the realm-marker class list) and is reserved: it is never a realm.
 *
 * Read by {@see HostIa}, and for gates by every gate reader, so the nav and the door share ONE source and precedence:
 * {@see \Splicewire\Beam\Realm\RealmManifestProjector}, {@see \Splicewire\Beam\Realm\RealmGateAbility} (the access
 * decision) and {@see \Splicewire\Beam\Surgeon\RealmGateCoverageAudit}.
 */
final class RealmProfiles
{
    /** The key under `beam.core.realms` that is not a realm. */
    public const RESERVED = ['classes'];

    /** The kit realms' defaults. `account` and `auth` are not realms. */
    private const KIT = [
        'site' => ['label' => 'Site', 'home' => 'home', 'surface' => 'site'],
        'tenant' => ['label' => 'App', 'home' => 'dashboard', 'surface' => 'app'],
        'user' => ['label' => 'Settings', 'home' => 'profile.edit', 'surface' => 'app'],
        'operator' => ['label' => 'Operator', 'home' => 'operator.home', 'surface' => 'app'],
    ];

    /**
     * A realm's profile: the kit default merged with the host's declaration.
     *
     * @return array{label: string, home: ?string, surface: string, gate: ?array}
     */
    public function for(string $key): array
    {
        $declared = in_array($key, self::RESERVED, true) ? [] : (array) config("beam.core.realms.{$key}", []);
        $kit = self::KIT[$key] ?? [];

        return [
            'label' => (string) ($declared['label'] ?? $kit['label'] ?? ucfirst(str_replace(['-', '_'], ' ', $key))),
            'home' => $declared['home'] ?? $kit['home'] ?? null,
            'surface' => (string) ($declared['surface'] ?? $kit['surface'] ?? 'app'),
            'gate' => $this->gate($key),
        ];
    }

    /**
     * The realm's gate: the profile's, else the deprecated `realm_gates` alias entry, else none.
     *
     * @return array<string, mixed>|null
     */
    public function gate(string $key): ?array
    {
        $declared = in_array($key, self::RESERVED, true) ? null : config("beam.core.realms.{$key}.gate");

        if (is_array($declared)) {
            return $declared;
        }

        $alias = (array) config('beam.core.realm_gates', config('beam.realm_gates', []));

        return isset($alias[$key]) && is_array($alias[$key]) ? $alias[$key] : null;
    }
}
