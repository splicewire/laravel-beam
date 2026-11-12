# Write the English where you declare it, and the key is free

**The rule.** Declared text stays an English literal on the declaration; never write a key there. Imperative text (an
abort, a validation message, a notification, a view) uses an explicit key in your package's namespace,
`<ns>::<group>.<name>`, with its English in `lang/en/<group>.php`, where the group is not `resources`, `data`, `enums`,
`actions`, `nav` or `groups`. Never use a sentence as a key, and never call `__()` on a value that might be a key or a
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

## Things that go wrong

- A route name with a `/`, or a context with a dot, cannot be keyed and is reported.
- One declaration registered twice with two English words is a defect: move the variant into a realm rung.
- A column with its own label never borrows its property's translated title.
- `group:` is an identity, not a word to translate.
