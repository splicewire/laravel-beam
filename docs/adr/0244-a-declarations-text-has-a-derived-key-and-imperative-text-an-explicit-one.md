# ADR-0244: A declaration's text has a derived key; imperative text has an explicit one

Status: accepted (localization ruling 01, amended, 2026-11-12; owner-overrulable). Landed by localization ticket 07 with
the ladder it names keys for. **Proposed amendment (localization 41, under review): the `config` kind below. Nothing
builds on it until it is accepted.**

**Derived keys.** Declared display text is translatable without editing the declaration. Its key is one fixed production
per kind: `resources`, `data`, `enums`, `actions`, `nav`, `groups`, `realms` (coord-lead rulings 2026-11-13 21:26Z and
21:49Z on localization 11, extending ruling 01's kind list; the grammar is unchanged), and `config` (localization 41,
proposed; the grammar is unchanged):

- a realm's label is `realms.<realm>.label`, in the realm's registering package's namespace;
- a realm capability's title is `realms.<realm>.capabilities.<capability>.title`, in the overlay registrant's namespace;
- upsell copy, string fields only (no URL or identifier), is
  - `beam::realms.<realm>.upsell.<field>` from beam-owned gate config, as brand copy is beam's;
  - `realms.<realm>.capabilities.<capability>.upsell.<field>` from a capability, in its registrant's namespace.
- a Config Section's title and description (`#[ConfigRoot(root:, title:, description:)]`, ADR-0245) are
  `config.<root>.title` and `config.<root>.description`, `<root>` the section's config root with its dots written `/`.
  The root is the section's identity: it keys the registry, the schema `$id` and the Config area. Its segments are
  registry key segments, which never contain `.` or `/`, so every registered root has a derived key. Examples:
  `beam::config.beam/core.title`, and for a host's `app.meters` section, `config.app/meters.title`.
- a Config Section's KEYS are not a new kind. Their titles and descriptions are the data-schemas `#[Title]` /
  `#[Description]` on their properties, so they keep the `data` productions exactly as the existing readers derive them
  (`TranslatedPropertyTextStrategy`, `BeamWidgetLabel`, `KeyCatalog`): `data.<C>.properties.<p>.title` / `.description`.
  `<C>` is each property's DECLARING class (`ReflectionProperty::getDeclaringClass()`), at every depth of nested Data,
  and the namespace is that class's owner. The section class (record root) or `entries:` class (map root) only selects
  where traversal begins; it never redefines a property's identity. An inherited property keeps its declaring class's
  key, so a host section that supersedes a vendor section and inherits its property reads the vendor key. Two cases
  differ:
  - A map entry's own class-level description is the `entries:` class's `data.<E>.description`, in `<E>`'s owner's
    namespace.
  - `ConfigRoot`'s own title and description (the bullet above) are the one case whose ownership follows the winning
    registry entry, on purpose.

Identifiers sit at fixed positions between fixed slot
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
- for a Config Section's own title and description, the registered section's owner, which `ConfigSectionRegistry`
  already records (a composer name, or `host`). A host that supersedes a package's section for a root owns that root's
  text (localization 41, proposed);
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

**Config text and config values (localization 41, proposed).**
- A Config Section's `ConfigRoot` title and description are its English lines.
- The committed section schema (`resources/schemas/config-sections/<root>.schema.json`) stays English: it is a catalog
  and a drift-checked artifact, never localized. The text resolves at config-sections' read surface, through the one
  resolver, with the declared literal as its default.
- Config VALUES have no derived key. Config-sections never translates a value, so the Override Ladder's value
  provenance stays true. A configured value shown as text (a meter's `label`) is keyed by its consumer in the host
  (localization 27), with the configured value as its English line (ruling 05 §2): a target-language line may beat it.
- Overriding a value and overriding text are independent. An operator's or tenant's value layer never changes
  declaration text, and a `lang/vendor/<ns>/<locale>/config.php` (or realm-rung) line never changes a value or its
  provenance.

**Imperative text.** It has an explicit key in the package's namespace, in a group that is not a kind word.

**Plurals.** A plural is selected by the rules of the locale whose line answered, and the committed plural fixture
(`tests/Fixtures/Localization/plural-selector.json`) is the cross-runtime contract for those rules. Plural counts are
non-negative by contract. For negative counts, Laravel 12.x and 13.x differ (13.x takes abs()); the fixture does not
cover them.

The full grammar, the per-kind productions and their worked keys are localization ruling 01 §2 to §4; the
user-facing-text convention (`docs/agents/user-facing-text.convention.md`) is the one page an agent writes text from.
