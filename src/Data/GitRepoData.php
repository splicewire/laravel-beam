<?php

namespace Splicewire\Beam\Data;

use Schemastud\Frame\Attributes\Column;
use Spatie\LaravelData\Attributes\MapName;
use Splicewire\Beam\Models\GitRepo;
use Splicewire\Beam\Particle\Attributes\ParticleResource;
use Splicewire\Beam\Storage\GitRepoRegistrar;

/**
 * The Frame resource declaration for `GitRepo` (mirror-status-ui ticket 02) — the SAME zero-glue
 * `ParticleResource` tier {@see BeamUxEntryData} uses (nothing to do with `BeamParticle`/versioning
 * despite the name; it's Frame's own REST-resource registration attribute), so it shows up in Admin's
 * resource-blind `/frame/manifest` list for free. `readOnly: true` — nothing writes to a `GitRepo`
 * through Frame; it's a cache {@see GitRepoRegistrar} owns exclusively.
 *
 * `BeamData` is beam's own base class, resolved as a sibling in this namespace and so left
 * unimported. Beam ships it so every DTO answers `::jsonSchema()` through the host's configured
 * generator (`66e2dff`) — a particle-declared DTO inside beam that skipped it was the one shape
 * beam's own doctrine could not describe.
 */
#[ParticleResource(
    key: 'git-repo',
    backing: GitRepo::class,
    label: 'Git repos',
    group: 'Ops',
    icon: 'git-branch',
    section: 'ops',
    readOnly: true,
)]
class GitRepoData extends BeamData
{
    // Read projection, camel on the wire (owner ruling 2026-10-09 18:18Z; docs/agents/wire-name.convention.md):
    // camel properties pinned with #[MapName], snake columns mapped explicitly in project(). No snake alias.
    public function __construct(
        public string $id,
        #[Column(label: 'Root', sort: 0)]
        #[MapName('rootPath')]
        public string $rootPath,
        #[Column(label: 'Branch', sort: 1)]
        public ?string $branch,
        #[Column(label: 'HEAD', sort: 2)]
        #[MapName('headSha')]
        public ?string $headSha,
        /** @var list<string> */
        #[MapName('dirtyPaths')]
        public array $dirtyPaths,
        /** @var list<string> */
        #[MapName('untrackedPaths')]
        public array $untrackedPaths,
        #[Column(label: 'Checked', sort: 3)]
        #[MapName('checkedAt')]
        public ?string $checkedAt,
    ) {}

    /** The explicit column => property map; the particle layer finds this by convention. */
    public static function project(GitRepo $model): self
    {
        return new self(
            id: (string) $model->getKey(),
            rootPath: (string) $model->root_path,
            branch: $model->branch,
            headSha: $model->head_sha,
            dirtyPaths: array_values((array) ($model->dirty_paths ?? [])),
            untrackedPaths: array_values((array) ($model->untracked_paths ?? [])),
            checkedAt: $model->checked_at?->toIso8601String(),
        );
    }
}
