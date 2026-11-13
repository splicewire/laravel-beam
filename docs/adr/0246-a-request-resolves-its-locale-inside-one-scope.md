# ADR-0246: A request resolves its locale, and answers inside one scope

Status: accepted (localization ruling 03 and owner decisions (a)–(e), 2026-11-12; owner-overrulable). Landed by
localization ticket 06.

**Supported languages.** `beam.localization` is a Config Section (ADR-0245). Every key is Host-tier with a Deploy
effect: `supported`, `fallback`, `pseudo`, `brand_default`, `http_profile` and `throw_on_contested_namespace`.
`supported` lists canonical tags (`es-MX`). Null means `app.locale` alone, so a host that lists nothing keeps answering
in the language it already uses. The application default is `app.locale` when supported, else `fallback`.

**Order.** `Localization\LocaleResolver` answers a request with the first supported answer:

1. an API client's `Accept-Language`;
2. the signed-in person's saved preference;
3. a browser's `Accept-Language`;
4. the tenant's default;
5. the brand's default;
6. the application default.

A header is read only under the localized HTTP profile (`http_profile`, owner decision (e)). Negotiation orders by `q`,
then position. It drops `q=0`, wildcards and malformed tags, and reaches a supported parent through the requested tag's
lineage (`es-AR` → `es`). It never descends into a child, so English never reaches `en-XA`.

**Credential, not route group.** A bearer token makes a request an API request, even when a cookie is also present. A
request with no session is an API request. A request with a session, signed in or not, is a browser request. The header
is considered once: at 1 for an API request, at 3 for a browser one.

**Sources are slots.** The person, tenant and brand rungs are `PersonLocaleSource`, `TenantLocaleSource` and
`BrandLocaleSource`. Beam binds each with `bindIf` to `NullLocaleSource`, which states no language. The owning package
contributes the real adapter: beam-accounts for the person (ticket 08), tenancy for the tenant (ticket 36), and beam's
config-backed brand adapter (ticket 11).

**Placement.** `Http\ResolveLocale` is placed twice:

- prepended to the global stack, so a 404, a 419 or an early 401 renders in a resolved language. No session exists
  there, so this is the reduced resolver: the header and the defaults, never the person;
- appended to the `web` and `api` groups, after the session has started, so the person and the credential count. It
  nests inside the global scope and restores to it.

Under the profile, the response carries `Content-Language` and a merged `Vary: Accept-Language`. When the language came
from the person or the tenant, `Cache-Control` becomes `private`, unless `no-store` is already set.

**One scope.** `LocaleScope::run($locale, $cb)` and `::runIn($locale, $realm, $cb)` set and restore four separate pieces
of state:

- the application and translator locale;
- `Number`'s static locale, which the framework never moves;
- Carbon's locale;
- the explicit realm.

The application locale is restored first, then `Number` and Carbon to their own saved values. A nested scope that
throws therefore restores its caller's state, not the default. Currency and timezone are never touched. Beam also
listens to `LocaleUpdated`, so `App::setLocale()` alone moves `Number`.

The realm a scope enters lives in `LocalizationContext`, bound `scoped()`. The translator reads it before the route. A
null realm entered means the base, and without a scope the route speaks (off HTTP, the base). `App::setLocale()` also
rewrites `config('app.locale')`, so inside a scope the application default reads the locale from before the outermost
scope. A job dispatched synchronously from a request never inherits that request's language.

**Deferred output and jobs.** `LocaleScope::capture()` returns a `CapturedLocale`. Output emitted after its scope ended
calls `enter()` at the emit point. A job opts in with `new Queue\WithLocaleScope($locale, $realm)`: a null locale enters
the application default, and a null realm enters the base. Beam installs no host-wide Bus pipeline.

**Delivery.** With no request, `forDelivery($recipient)` reads the recipient's preference, then the tenant, the brand
and the application default.

**The pseudo-locale.** `en-XA` joins `supported` only with `pseudo` on, and is never a negotiated parent. The
translator answers it from the source-language line, and `PseudoLocale::transform` marks, accents and pads it. A
`:parameter`, an HTML tag and a plural branch's condition stay byte-identical. Plural rules stay the source language's. **The rule:** the pseudo-locale marks text that passes through the ladder, by a derived or an explicit key. A
declared literal the ladder missed is its declaration's default, so it passes through `BeamTranslator::present()` and
is marked too. Only hard-coded text stays unmarked under `en-XA`, and so does a key with no line, which comes back as
the key itself. That unmarked text is what ticket 20's walkthrough must find.

**Contested namespaces.** `TextNamespaces` reads `throw_on_contested_namespace` (null: throw outside production). It no
longer reads the nav-seat switch `beam.ux.ia.throw`.
