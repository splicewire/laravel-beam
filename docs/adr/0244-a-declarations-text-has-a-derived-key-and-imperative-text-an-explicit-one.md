# ADR-0244: A declaration's text has a derived key; imperative text has an explicit one

Status: accepted (localization ruling 01, amended, 2026-11-12; owner-overrulable). Landed by localization ticket 07 with
the ladder it names keys for.

**Derived keys.** Declared display text is translatable without editing the declaration. Its key is one fixed production
per kind: `resources`, `data`, `enums`, `actions`, `nav`, `groups`. Identifiers sit at fixed positions between fixed slot
words, and each identifier is one dot-free segment:

- a registry key, operation name or route name has its dots written `/`;
- a class is its fully qualified name with `\` written `/`;
- a property or enum case is its PHP name.

**Rejection.** An identifier outside its admitted characters (a route name containing `/`, a context with a dot) gets no
derived key. It renders its literal and is reported. No escaping exists.

**Ownership.** The namespace is the owner's:

- for an attributed declaration, the annotated class's package (longest PSR-4 prefix);
- for an imperatively registered resource, the data class's package;
- for an action, the op class's package, or for a classless op its registering package (localization 08);
- for a nav section, the header seat's registrant;
- for a nav page, its authoring code;
- with none of these, the host, whose derived keys carry no namespace.

A namespace is the composer name less its vendor and a leading `laravel-`, or the package's declared
`extra.beam.lang-namespace`. Two packages that need one namespace are both refused (`Localization\TextNamespaces`). Beam
registers a translator hint for every used namespace, because the framework loads no lines, not even a host's vendor
override, for an unhinted one.

**Defaults and identity.** The declaration remains the English catalog: no source file exists for derived keys, and the
declared literal is the default when the ladder misses. A realm's configured label is that realm's English rung line
(localization ruling 05 §2; `Localization\BeamTranslator`). Declarations never carry keys. A resource's `group` is
identity at every reader, and a group's display name is `groups.<group>.name` in the registering namespace. A column with
its own literal never falls back to its property's title.

**Imperative text.** It has an explicit key in the package's namespace, in a group that is not a kind word.

**Plurals.** A plural is selected by the rules of the locale whose line answered, and the committed plural fixture
(`tests/Fixtures/Localization/plural-selector.json`) is the cross-runtime contract for those rules. Plural counts are
non-negative by contract. For negative counts, Laravel 12.x and 13.x differ (13.x takes abs()); the fixture does not
cover them.

The full grammar, the per-kind productions and their worked keys are localization ruling 01 §2 to §4; the
user-facing-text convention (`docs/agents/user-facing-text.convention.md`) is the one page an agent writes text from.
