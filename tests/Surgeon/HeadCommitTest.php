<?php

use Splicewire\Beam\Surgeon\InstallDesiredStateAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * `InstallDesiredStateAudit::headCommit()` reads HEAD straight out of `.git` (no shelling out) so R3
 * can attribute an install to a commit. Its byte-mirror is `bin/ci-check`'s `ci_head_commit()`, whose
 * worktree behaviour is proven in the starter's CiCheckRecordTest; this is the audit-side half.
 *
 * The defect these cover: a LINKED WORKTREE's `.git` is a FILE (`gitdir:` pointer), not a directory,
 * and the symbolic HEAD's ref lives in the COMMON git dir. Reading `.git/HEAD` as a literal path
 * returns null there — the shape every whiffletree space runs under — so R3 read every space install
 * as unattributable. Exercised through the REAL method (protected static, via reflection) with
 * base_path() pointed at a fixture, so the red half is observed without a defect in anyone's tree.
 */
uses(TestCase::class);

/** HEAD read from a `.git` layout planted under a throwaway base path (keyed by pid + uniqid). */
function headCommitUnder(Closure $plant): ?string
{
    $base = sys_get_temp_dir().'/beam-headcommit-'.getmypid().'-'.uniqid();
    mkdir($base, 0o775, true);

    try {
        $plant($base);
        app()->setBasePath($base);

        return (new ReflectionMethod(InstallDesiredStateAudit::class, 'headCommit'))->invoke(null);
    } finally {
        exec('rm -rf '.escapeshellarg($base));
    }
}

it('resolves HEAD through a linked-worktree gitdir pointer, not a literal .git/HEAD', function () {
    $sha = str_repeat('e', 40);

    $resolved = headCommitUnder(function (string $base) use ($sha) {
        // A linked worktree: .git is a FILE (gitdir: pointer); the symbolic HEAD lives under the
        // worktree git dir, and the ref it names lives in the COMMON git dir (commondir).
        mkdir($base.'/common.git/refs/heads/space', 0o775, true);
        mkdir($base.'/common.git/worktrees/wt', 0o775, true);
        file_put_contents($base.'/common.git/worktrees/wt/HEAD', "ref: refs/heads/space/x\n");
        file_put_contents($base.'/common.git/worktrees/wt/commondir', "../..\n");
        file_put_contents($base.'/common.git/refs/heads/space/x', $sha."\n");
        file_put_contents($base.'/.git', 'gitdir: '.$base.'/common.git/worktrees/wt'."\n");
    });

    expect($resolved)->toBe($sha);
});

it('still reads a plain checkout and a detached HEAD, and returns null off a tree', function () {
    // .git a DIR with a symbolic HEAD → the named ref under the same dir.
    $dirSha = str_repeat('a', 40);
    expect(headCommitUnder(function (string $base) use ($dirSha) {
        mkdir($base.'/.git/refs/heads', 0o775, true);
        file_put_contents($base.'/.git/HEAD', "ref: refs/heads/main\n");
        file_put_contents($base.'/.git/refs/heads/main', $dirSha."\n");
    }))->toBe($dirSha);

    // Detached HEAD → the sha it holds verbatim.
    $detachedSha = str_repeat('b', 40);
    expect(headCommitUnder(function (string $base) use ($detachedSha) {
        mkdir($base.'/.git', 0o775, true);
        file_put_contents($base.'/.git/HEAD', $detachedSha."\n");
    }))->toBe($detachedSha);

    // No .git at all → null, never a guess.
    expect(headCommitUnder(fn (string $base) => null))->toBeNull();
});
