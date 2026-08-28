<?php

namespace Splicewire\Beam\Data;

use Schemastud\Frame\Attributes\Column;
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
 * `Data` here is beam's OWN `Splicewire\Beam\Data\Data` — the sibling class in this
 * namespace — not `Spatie\LaravelData\Data`. The import is absent on purpose: beam ships that base
 * class so every DTO answers `::jsonSchema()` through the host's configured generator (`66e2dff`),
 * and a particle-declared DTO inside beam that skipped it was the one shape beam's own doctrine
 * could not describe.
 */
#[ParticleResource(
    key: 'git-repo',
    backing: GitRepo::class,
    label: 'Git Repos',
    group: 'Ops',
    icon: 'git-branch',
    section: 'ops',
    readOnly: true,
)]
class GitRepoData extends BeamData
{
    public function __construct(
        public string $id,
        #[Column(label: 'Root', sort: 0)]
        public string $root_path,
        #[Column(label: 'Branch', sort: 1)]
        public ?string $branch,
        #[Column(label: 'HEAD', sort: 2)]
        public ?string $head_sha,
        /** @var list<string> */
        public array $dirty_paths,
        /** @var list<string> */
        public array $untracked_paths,
        #[Column(label: 'Checked', sort: 3)]
        public ?string $checked_at,
    ) {}
}
