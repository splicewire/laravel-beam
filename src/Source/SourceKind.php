<?php

namespace Splicewire\Beam\Source;

use Splicewire\Beam\Schema\SchemaId;

/**
 * The authority axis of a particle reference (ADR-0161 §Decisions.1): WHO owns the
 * content behind a `$ref`, decided once by a {@see Contracts\ParticleSourceResolver} so
 * the hydrator and writer never prefix-sniff.
 *
 *  - {@see self::Local}     — a record this tenant owns; resolves through the local arm.
 *  - {@see self::Conduit}   — a third-party tool behind a `{provider}:{alias}.{tool}`
 *                             conduit reference (ADR-0110); dereferenced via the overlay.
 *  - {@see self::Federated} — another Splicewire tenant's fragment, addressed by
 *                             `{authority}` + `{externalRef}` (ADR-0126); the foreign
 *                             source-ref (kind #4) that reconciles onto the LOCAL schema
 *                             on read like any other particle.
 *
 * This is deliberately NOT the schema-identity grammar ({@see SchemaId},
 * `<base>/<name>/<version>`) nor the conduit-handle grammar: three separate grammars, one
 * resolver decides which is which (ADR-0161 §Decisions.4).
 */
enum SourceKind: string
{
    case Local = 'local';
    case Conduit = 'conduit';
    case Federated = 'federated';
}
