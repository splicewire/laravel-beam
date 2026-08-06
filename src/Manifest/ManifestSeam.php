<?php

namespace Splicewire\Beam\Manifest;

/**
 * The distinct INJECTION-POINT shapes the beam estate uses to let app/package code register into a
 * registry or manifest (the "index of indexes", beam-manifest-index). Each registry in the estate picks
 * exactly one shape by cardinality + ownership; this enum names them so `splicewire:beam:manifests` can
 * tell a host, per registry, HOW to inject — not just that a seam exists.
 *
 * The shapes were catalogued from the live estate (RouteManifestSource, BeamInstallManifest/BeamDoctor
 * manifest, DeclaredContractSource, the #[ParticleResource] scan, FrameResourceRegistry, the schema
 * registry chain) plus the write/read stage chains restored on the Particle.
 */
enum ManifestSeam: string
{
    /** An interface bound per key in a config array; the consumer reads the named implementations. */
    case ConfigSource = 'config-source';

    /** A container singleton consumers `register()` DOWN into from their own provider (beam never learns names). */
    case SingletonAccumulator = 'singleton-accumulator';

    /** Each package declares its own rules in `composer.json` `extra.*`; a source merges every fragment. */
    case DeclaredComposer = 'declared-composer';

    /** A PHP `#[Attribute]` discovered by a filesystem scan and cached to a build-time manifest. */
    case AttributeScan = 'attribute-scan';

    /** A constructor-injected key→handler map, app-owned and finite, falling back to a default on miss. */
    case ConstructorPolicy = 'constructor-policy';

    /** Ordered sources walked first-match (read from the first that resolves; write to the first). */
    case ChainedLookup = 'chained-lookup';

    /** An ordered list of pipe stages; a host inserts its own pipe to compose a cross-cutting concern. */
    case PipelineChain = 'pipeline-chain';

    /** A one-line account of how a host injects into a seam of this shape. */
    public function blurb(): string
    {
        return match ($this) {
            self::ConfigSource => 'Bind an implementation class-string per key in the named config array.',
            self::SingletonAccumulator => 'From your provider, resolve the singleton and call register(...) — order-ascending, core-first.',
            self::DeclaredComposer => 'Add an extra.* block to your package composer.json; it is merged with every other package\'s.',
            self::AttributeScan => 'Annotate a class with the attribute (or add its dir to the discover-paths); cache rebuilds on optimize.',
            self::ConstructorPolicy => 'Add your key→handler entry to the config map the registry is constructed from (default handler on miss).',
            self::ChainedLookup => 'Add a source to the ordered list; lookups walk it first-match, writes go to the first source.',
            self::PipelineChain => 'Pass an ordered $stages list (or extend the default) — insert your pipe where the concern belongs.',
        };
    }
}
