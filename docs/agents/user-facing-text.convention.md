# Write the English where you declare it, and the key is free

**The rule.** Declared text stays an English literal on the declaration; never write a key there. Imperative text (an
abort, a validation message, a notification, a view) uses an explicit key in your package's namespace,
`<ns>::<group>.<name>`, with its English in `lang/en/<group>.php`, where the group is not `resources`, `data`, `enums`,
`actions`, `nav`, `groups` or `realms`. Never use a sentence as a key, and never call `__()` on a value that might be a key or a
literal.

Decided by [ADR-0244](../adr/0244-a-declarations-text-has-a-derived-key-and-imperative-text-an-explicit-one.md)
(localization ruling 01).

## Derived keys you can override

Override them from your host (`lang/vendor/<ns>/<locale>/<kind>.php`) or from a realm rung
(`lang/vendor/<ns>/<locale>/<realm>/<kind>.php`). `C` is the class's fully qualified name with `\` written `/`; a dotted
key or route name has its dots written `/`.

| you declared | the key |
|---|---|
| `#[ParticleResource(key: 'k', label:, singularLabel:)]` | `<ns>::resources.k.label`, `.singular` |
| `#[Title]`/`#[Description]` on a class | `<ns>::data.C.title`, `.description` |
| the same on property `$p` | `<ns>::data.C.properties.p.title`, `.description` |
| `#[Column(label:)]` / `#[WidgetIn('ctx', label:)]` on `$p` | `<ns>::data.C.properties.p.contexts.ctx.label` |
| an enum with `#[Title]` and `label()` | `<ns>::enums.C.title`, `.cases.<CaseName>.label` |
| `ActionAffordance(label:)` on op `o` of resource `r` | `<ns>::actions.r.o.label` |
| `NavSection(key: 's')`, `NavPage(routeName: 'a.b')` | `<ns>::nav.sections.s.label`, `<ns>::nav.routes.a/b.title` |
| a realm `r` (its label from `beam.core.realms.r.label`) | `<ns>::realms.r.label`, `<ns>` the realm's registrant (a realm the host declares: `realms.r.label`) |
| a realm overlay's capability `c` (`title`, `upsell`) | `<ns>::realms.r.capabilities.c.title`, `….upsell.<field>`, `<ns>` the overlay's registrant |
| a realm gate's upsell (`beam.core.realms.r.gate.upsell`) | `beam::realms.r.upsell.<field>`; copy fields only, never a URL or an identifier |
| brand copy (`beam.brand.*`; not a declaration) | explicit `beam::brand.tagline`, `.passkey_copy`, `.title_template`; `name` and `legal_entity` are not keyed |

`<ns>` is your composer name less the vendor and a leading `laravel-` (`splicewire/laravel-beam-ux` → `beam-ux`). If two
installed packages shorten to one name, neither gets derived keys until one declares `extra.beam.lang-namespace` in its
`composer.json`. A host's own declarations take the same keys with no `<ns>::`.

## How a lookup is answered

One ladder answers every lookup, whatever called it (`__()`, `trans_choice()`, Blade, a validation message). The
**locale is the outer loop**: the requested locale, its parent languages, then the fallback. The **realm is the inner
loop**: the realm, its stack from most to least specific, then the base. So, at each locale:

1. Each realm rung's file.
2. In English only, a realm's configured resource `label`, just below that rung's file.
3. The base file.

The realm comes from the request's `realm` route parameter, or is passed by the code that already holds one
(`BeamTranslator::getIn()`); beam never stores it. A plural is selected by the rules of the locale whose line answered.
Plural counts are non-negative by contract. For negative counts, Laravel 12.x and 13.x differ (13.x takes abs()); the
fixture does not cover them.
To see which rung answers a key: `php artisan beam:lang:rungs <key> --locale=<locale> --realm=<realm>`.

## Errors a client can receive (localization 13)

**Text is user-facing when a renderer puts it in a response body, a notification, a mail or a view.** Everything else
is developer text and is not translated: a `LogicException` that means "beam was misused", console and doctor output, a
log line.

- **A user-facing error is a coded error:**
  - a string-backed enum implementing `CodedError` with the `IsCodedError` trait;
  - each case's **value is its stable wire code**, and its English is declared on the case with `#[Message('…')]`;
  - its key is derived (`<ns>::enums.<Enum>.cases.<Case>.message`), so it is translatable and overridable like any
    declared text.
- **To refuse,** `throw CodedValidationException::for($field, MyError::Case, ['name' => $value])`. A JSON request gets a
  422 `CodedValidationErrorData`: Laravel's `{message, errors}` unchanged, plus `codes` at the same field and position.
  Clients and tests match `codes`, never the prose.
- **One sentence per case.** Never glue a message from fragments. A variant (an empty list, a different subject) is its
  own case with its own whole sentence.
- **An arbitrary exception string is a diagnostic, never a translation key.** Never `__($e->getMessage())`. A product
  failure renders a stable, localized, generic coded case chosen from state you know. The raw message stays in the logs,
  and what the response discloses does not change.

The exemplar is `Splicewire\Beam\Webhooks\HookSubscriptionError`, the hook-subscription create path.

## Things that go wrong

- A route name with a `/`, or a context with a dot, cannot be keyed and is reported.
- One declaration registered twice with two English words is a defect: move the variant into a realm rung.
- A column with its own label never borrows its property's translated title.
- `group:` is an identity, not a word to translate.

## What fails, and what the doctor reports (localization 12)

- **A mistyped key fails its test.** While unit tests run, a namespaced key with no line throws `MissingTranslationKey`,
  but only in a namespace a splicewire, schemastud or rushing package owns. A host's own keys, sentence keys and
  third-party namespaces never throw. `beam.localization.throw_on_missing_key` (null: on in tests only) switches it.
- **The doctor:** `localization.unresolved-literal-key`, `localization.realm-line-without-base`,
  `localization.underived-key`, `localization.coverage` and `localization.english-derivation` (a framed label or
  singular, or an action label, left to English inflection: declare it). Each names the population it read.
- **The ratchet.** `lang-ratchet.json` at your package root counts literals in response-reaching calls: `abort*`,
  `HttpException`, `withMessages`/`withErrors`, `response()->json` messages, mail builders and notification payloads.
  Test it with `AssertsTextRatchet`, and re-write it with `php artisan beam:lang:ratchet --write`. It may only fall.
  Blade views are not counted yet.
- **For a translator:** `php artisan beam:lang:keys` lists every key a host can emit with its English, and
  `php artisan beam:lang:missing <locale>` exports what a language lacks. The pseudo-locale lacks nothing.

Until localization 16 these read PHP-emitted keys only; client keys join through the discovered catalog.
