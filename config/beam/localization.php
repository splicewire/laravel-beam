<?php

/*
|--------------------------------------------------------------------------
| beam localization (localization 06; ruling 03)
|--------------------------------------------------------------------------
| How a request's language is resolved, and which languages this host ships. Declared as a Config Section
| (Splicewire\Beam\Localization\Config\LocalizationSection, ADR-0245): every key is Host-tier, Deploy effect.
|
| Locale tags are canonical hyphen tags (`es-MX`), and lang directories are named the same way.
*/

return [

    // The languages this host ships, as canonical tags. Negotiation only ever chooses one of these; a catalog may
    // still fall back to a parent file (es-MX → es) without that parent being advertised here. Null: `app.locale` alone,
    // so a host that has not listed its languages keeps answering in the language it already does.
    'supported' => null,

    // The last rung of the locale chain, and the application default when `app.locale` is not supported.
    'fallback' => 'en',

    // The pseudo-locale `en-XA`, derived from English, for development and proof (ticket 20). Off by default; when on,
    // `en-XA` joins the supported list. Never chosen from a wildcard or as a default.
    'pseudo' => false,

    // The brand's default language, a config-backed server source (ruling 03, owner decision (c)). Read by ticket 11's
    // brand adapter; null means the brand states none.
    'brand_default' => null,

    // The opt-in localized HTTP profile (ruling 03 §5, owner decision (e)): Accept-Language negotiation, Content-Language,
    // a merged `Vary: Accept-Language`, and private responses when the language came from the person or workspace.
    // Off, a request still resolves from saved preference and defaults, and no header is read or written.
    'http_profile' => false,

    // Whether a namespace two installed packages shorten to (ruling 01 §3) throws where the host is built (true) or is
    // reported and its declarations render their literal (false). Null: throw outside production.
    'throw_on_contested_namespace' => null,

    // Whether a NAMESPACED key with no line (`beam::…`, `beam-ux::…`) throws instead of rendering the key itself
    // (localization 12). Null: throw while unit tests run, never otherwise, so a mistyped key fails its test and never
    // ships as a raw key. Sentence keys and the framework's own groups are never affected; a derived key's miss renders
    // its declaration and never reaches this.
    'throw_on_missing_key' => null,

    // The client catalog (localization 16; ruling 02 §3–§4). It's the file `schemastud-messages collect --out
    // resources/beam/messages.json --write` writes; beam reads it, validates its digest, and serves the resolved
    // catalog at GET beam/localization/catalog/{locale}/{realm?}.
    'client_catalog' => 'resources/beam/messages.json',

    // Seconds a resolved catalog stays memoized. The memo key already includes the catalog digest and the content
    // fingerprints of every lang file and the realm config, so a change never waits for this to expire.
    'client_catalog_ttl' => 86400,

    // The catalog endpoint's middleware: the host's own authorization for its beam routes (no new permission surface).
    'client_catalog_middleware' => ['web'],

];
