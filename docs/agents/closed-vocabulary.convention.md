# Declare a closed set as an enum, and the client type is free

**The rule.** A Data property whose values are a closed set of strings is typed as a PHP backed enum.
Never type it `string` and spell the set in `#[LiteralTypeScriptType]`, a docblock union or class
constants. The enum's case values are the wire values; PHP, the JSON Schema and the generated client
then read one declaration.

**An object with named members is a Data class.** Never write its type by hand in an attribute.

**`#[LiteralTypeScriptType]` is for what a PHP type and a docblock cannot say:** one literal naming a
union arm (equal to the property's default), a version number, `unknown`, a union with a literal
member. Where a docblock or `#[TypeScriptType]` can say it, use that.

**An open set is not a union.** Type it to an interface a host's enum can implement, or leave it
`string`.

**Prove the enum reaches every host that serves the class.** A family enum goes in its package's
`Enums` directory. Declared reach finds it anywhere; a host that discovers the class only by scanning
directories finds it only there. Regenerate, and read the run's log for an unresolved reference.

Enforced advisorily by `Splicewire\Beam\Surgeon\ClosedVocabularyAudit` (`typescript.literal-vocabulary`,
`typescript.literal-inline-object`, `typescript.literal-discriminator-mismatch`,
`typescript.docblock-vocabulary`), which reports through `surgeon:audit`.
