<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\DeadConfigKeyAudit;
use Splicewire\Beam\Doctor\Support\ConfigKeyScanner;
use Splicewire\Beam\Tests\TestCase;

/**
 * The check that would have caught all four occurrences of the estate's recurring dead-config-key bug.
 *
 * The cases here are the ones that decide whether the audit is usable rather than ignored: a rename
 * leaves migration notes behind by definition, so the comment population is guaranteed non-empty on
 * exactly the keys this audit hunts — an audit that reports the file DOCUMENTING the fix as the file
 * needing it gets its floor bumped and then deleted.
 */
class DeadConfigKeyAuditTest extends TestCase
{
    public function test_it_finds_a_literal_config_root_and_ignores_prose_naming_the_same_key(): void
    {
        $source = <<<'CODE'
        <?php
        // Renamed from config('beam-mdx.content_path') — prose, not a read.
        /** {@see config('beam-legacy.thing')} */
        $live = config('beam.commerce.webhook.path', 'x');
        $dead = config('beam-commerce.webhook.middleware', ['api']);
        CODE;

        $roots = ConfigKeyScanner::rootsInSource($source);

        $this->assertSame(['beam', 'beam-commerce'], array_keys($roots));
        $this->assertArrayNotHasKey('beam-mdx', $roots, 'a commented key is prose, not a read');
        $this->assertArrayNotHasKey('beam-legacy', $roots, 'a {@see} tag is prose, not a read');
    }

    public function test_it_reads_the_config_facade_but_not_an_arbitrary_get(): void
    {
        $source = <<<'CODE'
        <?php
        $a = Config::get('beam-workflows.status_log_name');
        $b = \Config::has('docs-regenerate.guides');
        $c = $collection->get('not.a.config');
        $d = $this->repository->get('also.not.config');
        CODE;

        $this->assertSame(
            ['beam-workflows', 'docs-regenerate'],
            array_keys(ConfigKeyScanner::rootsInSource($source)),
        );
    }

    public function test_a_dynamic_or_interpolated_key_is_invisible_rather_than_guessed_at(): void
    {
        $source = <<<'CODE'
        <?php
        $a = config($key);
        $b = config("beam.{$domain}.path");
        $c = config('standalone');
        CODE;

        // A deliberate false negative: a key that cannot be statically resolved must not be guessed at,
        // and a rootless `config('standalone')` is a legitimate is-it-installed probe.
        $this->assertSame([], ConfigKeyScanner::rootsInSource($source));
    }

    public function test_the_abandoned_flat_twin_fails_while_an_unloaded_root_only_warns(): void
    {
        config(['beam' => ['commerce' => ['webhook' => ['path' => 'commerce/stripe/{party}']]]]);

        $audit = new class extends DeadConfigKeyAudit
        {
            protected function files(): array
            {
                return [];
            }
        };

        // `beam-commerce` is absent AND `beam.commerce` is loaded → the reader is provably on the wrong
        // side of a rename, which is a failure rather than an optimistic read of an absent package.
        $this->assertSame(DoctorStatus::Fail, $this->findingFor($audit, 'beam-commerce')->status);

        // Nothing named `totally-absent` is loaded and no dotted twin exists → the owning package may
        // simply be uninstalled, so this is a warning.
        $this->assertSame(DoctorStatus::Warn, $this->findingFor($audit, 'totally-absent')->status);
    }

    public function test_a_clean_codebase_passes(): void
    {
        $audit = new class extends DeadConfigKeyAudit
        {
            protected function files(): array
            {
                return [];
            }
        };

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
    }

    private function findingFor(DeadConfigKeyAudit $audit, string $root): Finding
    {
        $method = new \ReflectionMethod($audit, 'finding');

        return $method->invoke($audit, $root, ['some/file.php:12'], array_keys((array) config()->all()));
    }
}
