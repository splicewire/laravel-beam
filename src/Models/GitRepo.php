<?php

namespace Splicewire\Beam\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Data\GitRepoData;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Storage\GitRepoRegistrar;

/**
 * One known git repo root (mirror-status-ui ticket 02) — auto-`firstOrCreate`d by
 * {@see GitRepoRegistrar::forFile()} the first time anything resolves the
 * nearest `.git` above a file, then refreshed in place (never a second row for the same root — unique
 * on `root_path`). A cache, not authored content: nothing writes to it through Frame
 * ({@see GitRepoData}'s `readOnly: true`).
 *
 * `dirty_paths`/`untracked_paths`/`tracked_paths` are repo-root-RELATIVE paths from a single
 * `git status --porcelain` + `git ls-files` pair, spawned once per refresh regardless of how many
 * files a caller asks about — the whole point (`GitRepoRegistrar`'s own docblock has the N→~1 math).
 *
 * The table name is resolved through the single beam table-prefix seam ({@see Beam::table()}) — same
 * pattern {@see BeamParticle} uses — so a retrofit host's one prefix override follows here too.
 */
class GitRepo extends Model
{
    use HasUuids;

    public function getTable(): string
    {
        return Beam::table('git_repos');
    }

    protected $fillable = [
        'root_path',
        'branch',
        'head_sha',
        'dirty_paths',
        'untracked_paths',
        'tracked_paths',
        'checked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dirty_paths' => 'array',
            'untracked_paths' => 'array',
            'tracked_paths' => 'array',
            'checked_at' => 'datetime',
        ];
    }
}
