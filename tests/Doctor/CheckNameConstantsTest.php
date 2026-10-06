<?php

namespace Splicewire\Beam\Tests\Doctor;

use Symfony\Component\Finder\Finder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Splicewire\Beam\Doctor\BeamDependencyContractAudit;
use Splicewire\Beam\Doctor\FrameManifestAudit;

/**
 * docs-walkthrough DOC-11(c), DOCS-09: a doctor check name is a CLASS CONSTANT, so a docs page that quotes a
 * `<DoctorOutput>` sample can be checked against the names the doctor can actually emit. The published sample named
 * `spine ready`, `schema-forms` and `registries`, which no audit has ever emitted, because the names were inline
 * strings nothing could enumerate.
 *
 * The ratchet is structural: no audit under `src/Doctor` assigns a check name from a string literal, or hands one
 * straight to a `Finding`.
 */
class CheckNameConstantsTest extends TestCase
{
    public function test_no_doctor_audit_names_a_check_with_an_inline_string(): void
    {
        $inline = [];
        foreach (Finder::create()->files()->name('*.php')->in(dirname(__DIR__, 2).'/src/Doctor') as $file) {
            $source = $file->getContents();
            if (preg_match_all('/\$check\s*=\s*[\'"]|Finding::(?:pass|warn|fail|inconclusive)\(\s*[\'"]/', $source, $m) > 0) {
                $inline[] = $file->getRelativePathname().' ('.count($m[0]).')';
            }
        }

        $this->assertSame([], $inline, 'Name these checks with a class constant (`public const CHECK… = \'…\'`).');
    }

    public function test_the_head_audits_expose_the_names_they_emit(): void
    {
        $this->assertSame([
            'CHECK_MARQUEE_DECLARED' => 'marquee site-mode gate wired',
            'CHECK_FIRST_PARTY_CLOSURE' => 'first-party closure declared in repositories',
            'CHECK_LOCK_PATH_FREE' => 'lock path-free',
            'CHECK_REPOS_GIT_RESOLVED' => 'repos git-resolved',
            'CHECK_STABILITY' => 'stability configured',
        ], array_filter(
            (new ReflectionClass(BeamDependencyContractAudit::class))->getConstants(),
            fn (string $name): bool => str_starts_with($name, 'CHECK'),
            ARRAY_FILTER_USE_KEY,
        ));
        $this->assertSame('frame manifest', FrameManifestAudit::CHECK);
    }
}
