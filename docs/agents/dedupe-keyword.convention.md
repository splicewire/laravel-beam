# The dedupe keyword — convention

**Status:** canon for any schema in the family that declares `x-beam-dedupe`, and for any model that
wants to accept it.
**Decided:** 2026-08-21 (beam-facade ticket 50), built 2026-08-22 (ticket 66).
**Mechanism:** `Splicewire\Beam\Schema\Keywords::Dedupe`, `Splicewire\Beam\Write\Stages\DedupeStage`,
`Splicewire\Beam\Concerns\Deduplicates` — all in `splicewire/laravel-beam`. This page states the
rules; the classes document the machinery.

This is the first keyword **beam-core** owns. `x-beam-notify` belongs to
`splicewire/laravel-beam-notifications` and stays there; dedupe is homed here because dedupe is a
property of *capture*, and capture is core's — the stage runs in the shipped default write chain, so
homing it in an optional package would tie a write-time concern to something a host may not compose.

## The shape

```jsonc
{
  "type": "object",
  "properties": { "email": { "type": "string" } },
  "x-beam-dedupe": { "by": ["email"], "mode": "admit" }
}
```

- **`by`** — the ordered list of payload fields the match key is computed from. Required, non-empty.
- **`mode`** — `reject | ignore | admit`. Optional; **defaults to `admit`**.

A malformed declaration **throws**. It does not degrade to a no-op: a keyword that quietly does
nothing is worse than one that refuses (ticket 40's `x-beam-notify` defect — effective for one path,
inert for another, flagged by nothing, wrong for a year).

## The three modes are all LEDGER-side

| mode | the repeat | the response | the event |
| --- | --- | --- | --- |
| `admit` (default) | **lands**, stamped with its key and `meta.dedupe.first_seen_id` | the new row | `BeamParticlePersisted` fires |
| `ignore` | dropped | **the row that matched**, indistinguishably | **nothing fires** |
| `reject` | refused | `DuplicateRejected` → 409 | **nothing fires** |

`update` and `version` are deliberately **not** legal values. They are about *the subject* rather
than the ledger, and `version` would additionally reverse a documented ruling — `BeamSubmission`
composes `ReconcilesPayloadOnRead` and pointedly not `Versionable`: *a submission is immutable
capture, not an editable milestone-versioned doc*. Adding a fourth mode later is additive; that
argument is the strongest objection to doing it.

`admit` is the default because it is the only mode that preserves the capture ledger's append-only
contract. Under a suppressing default, a repeat's provenance — a different ip, source, user-agent, a
later `submitted_at` — is destroyed to save a row, and *"we captured 400 signups, 340 distinct"* is
answerable one way and unanswerable the other.

## Precedence: authorize → validate → **dedupe** → persist

A rule, not an implementation detail. **An unauthorized duplicate is a 403 and an invalid duplicate
is a 422** — a dedupe verdict never preempts a gate, because a caller who may not write at all, or
whose payload does not conform, must not learn from a 409 that the address is already on the list.

The stage is in the **shipped default chain**, not host-passed. `RecordsSubmissions` constructs its
own 4-arg `ParticleWriter` — the default chain — and it is the path the hosts that want dedupe
actually call, so a host-passed stage would never fire for either of them.

## `ignore` must stay indistinguishable from a fresh capture

**A security property, not a nicety.** Under `ignore` the pipeline hands back the row that *matched*,
and the caller must not be able to tell that apart from a first capture — same status, same body
shape, a real id. Otherwise a public door becomes an **email-existence oracle**: an anonymous caller
probes an address and learns from the status code, an absent id, or a different id whether it is
already on the list. `splicewire-app`'s `LeadController` already demands exactly this of its honeypot
branch, so the estate has met the constraint before and written it down.

The consequence for callers is concrete: **read the written model off `ParticleWriter::write()`'s
return value, never off the instance you passed in.** They are different objects under `ignore`, and
reporting the instance answers a repeat with a null id — which is the oracle arriving through the
success body instead of the status code. `PublicIntakeDedupeTest` is the gate on it.

## `reject` IS an oracle, by construction

The 409 *is* the disclosure. That is what the mode means, so it cannot be fixed — it can only be
placed. **`reject` is legitimate only behind an authenticated or non-public door.** Nothing in the
estate stops a host declaring it on a public intake schema, which is why the ruling lives here.

`ignore` is the mode a public door wants.

## The key recipe is WRITE-ONCE

sha256 over the capture **scope** plus the declared field values, in the keyword's **declared order**,
string values trimmed and casefolded. A missing, empty, or non-scalar declared field means **no key at
all** — never a key over the absence, which would collide every payload missing the field with every
other one, and under `reject` refuse all of them.

Once rows carry keys, changing the normalization, the ordering, the separator or the scope silently
partitions old rows from new — ticket 61's `$id` lesson in another medium, and nothing in the estate
can flag it, because both halves are valid digests. `DedupeStageTest` pins a **literal expected
digest** for a fixed input so an edit fails loudly. **If the recipe must genuinely change, that is a
backfill, not an edit.**

The scope is the model's `dedupeScope()` — by default its **capture kind** (`capture_key`), never
`schema_ref`. `schema_ref` is a *versioned* absolute `$id`, so re-stemming a schema would silently
reset the host's whole dedupe universe; the beam-facade map re-stemmed twice. Folding the scope
*inside* the hash is what makes `dedupe_key` globally comparable on its own, so a host's distinct
count is a bare `distinct('dedupe_key')` over one plain index with no where-clause.

## Storage, and opting a model in

A nullable, **plainly indexed** (never unique) `dedupe_key` column, plus `meta.dedupe.first_seen_id`
for the linkage. No ordinal — derivable, and wrong under concurrency. A real column and not a `meta`
key because "how many distinct captures" is a page a host renders, and a JSON-path query indexes
differently across sqlite-in-tests and MySQL.

Nine models compose `PersistsBeamParticle`, each keeping its own table, and the stage is in the
default chain — so **dedupe is opt-in per model**. A model accepts the keyword by composing
`Splicewire\Beam\Concerns\Deduplicates` and carrying the column. A schema declaring the keyword
against a model that has not **throws `DedupeNotSupported`**, which is a wiring error and rightly a
500, not a status code a submitter sees.

Today that is `BeamParticle` and `BeamSubmission`, on both base tables.

## Notify is untouched, and this is the easiest consequence to miss

`x-beam-dedupe` and `x-beam-notify` are uncoupled on purpose — making one keyword's behaviour depend
on another's presence is precisely the interaction ticket 40 showed is invisible until it misfires.

So: under `admit` a repeat persists, the event fires, and a host composing beam-notifications **mails
on every re-signup** (which is what fable and entreport do today, so `admit` is behaviour-preserving).
Under **`ignore` and `reject` nothing persists, so there is no event and NO NOTIFICATION** — those two
modes silence the mail. The trigger to revisit is a host complaining about duplicate notifications.

## No audit — tests instead

Deliberate, on ticket 30's own rule: a conformance check flags its whole population until that
population is swept, and this population is **two hosts**. The mode semantics, the
indistinguishability property and the pinned digest are asserted in `DedupeStageTest`,
`PublicIntakeDedupeTest` and `KeywordOwnershipTest`, which re-run where an audit would only nag.

**The audit's trigger is a third host adopting the keyword.**
