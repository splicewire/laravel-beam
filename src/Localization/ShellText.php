<?php

namespace Splicewire\Beam\Localization;

use Splicewire\Beam\Realm\RealmRegistry;

/**
 * The shell's own text, resolved where it is emitted (localization 11): a realm's label (the host IA's realms, the
 * launcher's titles), nav section labels and nav page titles. Each goes through the one resolver,
 * {@see DeclaredText::resolve()}, with the configured or declared text as its default.
 *
 * **A realm's label** (coord-lead ruling 21:26Z, extending ruling 01's kind list): `<ns>::realms.<realm>.label` in the
 * namespace of the package that REGISTERED the realm, so a kit realm is beam's and a realm the host declares is the
 * host's (`realms.<realm>.label`, unnamespaced). The configured label is the English default.
 *
 * **Nav** (ruling 01 §3): `<ns>::nav.sections.<section>.label`, `<ns>` the registrant of the header seat, the key
 * carrying no realm; `<ns>::nav.routes.<a/b>.title` by the authoring code (a host page is a host key). A node with no
 * route name has no identity and no key: its literal renders (and `localization.unkeyable` reports it).
 */
final class ShellText
{
    public function __construct(
        private DeclaredText $text,
        private TextNamespaces $namespaces,
    ) {}

    /**
     * The key prefix of a registrant: the host for null, `''` or `host`; a `class:<FQCN>` or bare class's owner; a
     * composer name's package. An UNRECOGNISED registrant gets null (coord-lead ruling 22:06Z: never the host, whose
     * unnamespaced keys a package's text would collide with), so no derived key forms, its literal renders, and
     * `localization.unkeyable` reports it.
     */
    public function prefixOfRegistrant(?string $registrant): ?string
    {
        if ($registrant === null || $registrant === '' || $registrant === 'host') {
            return '';
        }
        $class = str_starts_with($registrant, 'class:') ? substr($registrant, 6) : $registrant;
        if (class_exists($class) || interface_exists($class) || enum_exists($class)) {
            return $this->namespaces->ownerOfClass($class)->keyPrefix();
        }
        if (self::isComposerName($registrant)) {
            return $this->namespaces->ownerOfPackage($registrant)->keyPrefix();
        }

        return null;
    }

    /** Whether a registrant is one the key grammar can place: the host, a class, or a composer name. */
    public static function isRecognised(?string $registrant): bool
    {
        if ($registrant === null || $registrant === '' || $registrant === 'host') {
            return true;
        }
        $class = str_starts_with($registrant, 'class:') ? substr($registrant, 6) : $registrant;

        return class_exists($class) || interface_exists($class) || enum_exists($class) || self::isComposerName($registrant);
    }

    private static function isComposerName(string $registrant): bool
    {
        return preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#', $registrant) === 1;
    }

    /** A realm's label in the request's language; `$default` is the configured (or kit) label. */
    public function realmLabel(string $realm, string $default): string
    {
        $registrant = app()->bound(RealmRegistry::class) ? app(RealmRegistry::class)->registrantOf($realm) : null;
        $key = DeclaredText::derive($this->prefixOfRegistrant($registrant), 'realms', [$realm], 'label');

        return (string) $this->text->resolve($key, $default);
    }

    /**
     * A realm capability's title (coord-lead ruling 21:49Z): `<ns>::realms.<realm>.capabilities.<capability>.title`, `<ns>`
     * the overlay's registrant; `$default` is the declared title, else the capability key.
     */
    public function capabilityTitle(string $realm, string $capability, ?string $registrant, string $default): string
    {
        $key = DeclaredText::derive($this->prefixOfRegistrant($registrant), 'realms', [$realm], 'capabilities.'.self::segment($capability).'.title');

        return (string) $this->text->resolve($key, $default);
    }

    /**
     * Upsell copy in the request's language (coord-lead ruling 21:49Z), keyed `<base>.upsell.<field>`: a realm gate's
     * from beam-owned config is `beam::realms.<realm>.upsell.<field>`, a capability's is
     * `<ns>::realms.<realm>.capabilities.<capability>.upsell.<field>`. Only STRING fields that are copy: a URL, a path or
     * an identifier field (`href`, `url`, `plan`, `key`, …) is never keyed. Each default is the configured string.
     *
     * @param  array<array-key, mixed>|null  $upsell  configured or declared payload; a non-string key is left alone
     * @return array<array-key, mixed>|null
     */
    public function upsell(?array $upsell, ?string $prefix, string $realm, ?string $capability = null): ?array
    {
        if ($upsell === null || $prefix === null) {
            return $upsell;
        }
        $base = $capability === null ? 'upsell' : 'capabilities.'.self::segment($capability).'.upsell';

        foreach ($upsell as $field => $value) {
            if (! is_string($field) || ! is_string($value) || $value === '' || ! self::isCopy($field, $value)) {
                continue;
            }
            $key = DeclaredText::derive($prefix, 'realms', [$realm], $base.'.'.self::segment($field));
            $upsell[$field] = (string) $this->text->resolve($key, $value);
        }

        return $upsell;
    }

    /** Whether an upsell field is copy a person reads, not a URL, path or identifier. */
    public static function isCopy(string $field, string $value): bool
    {
        if (preg_match('/(url|href|link|route|path|key|id|slug|plan|entitlement|icon|image|price|currency|sku|email)$/i', $field) === 1) {
            return false;
        }

        return preg_match('#^(https?://|mailto:|/|\#)#i', $value) !== 1;
    }

    /** One key segment: a dot would split it, so it is written `/` (ruling 01 §2). */
    private static function segment(string $identity): string
    {
        return str_replace('.', '/', $identity);
    }

    /** A nav section's label; `$registrant` is the header seat's registrant. */
    public function navSectionLabel(string $section, ?string $registrant, string $default): string
    {
        $key = DeclaredText::derive($this->prefixOfRegistrant($registrant), 'nav', ['sections', $section], 'label');

        return (string) $this->text->resolve($key, $default);
    }

    /**
     * A nav page's (or route leaf's) title, keyed by its route name; null `$routeName`: no identity, the literal stands
     * (presented for the active locale like any declared default).
     */
    public function navRouteTitle(?string $routeName, ?string $registrant, string $default): string
    {
        $key = $routeName === null || $routeName === '' ? null
            : DeclaredText::derive($this->prefixOfRegistrant($registrant), 'nav', ['routes', $routeName], 'title');

        return (string) $this->text->resolve($key, $default);
    }
}
