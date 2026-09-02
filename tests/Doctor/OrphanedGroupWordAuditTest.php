<?php

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Doctor\OrphanedGroupWordAudit;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Particle\ParticleResource;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Surface\ApiGroup;
use Splicewire\Beam\Surface\GroupRegistry;
use Splicewire\Beam\Tests\TestCase;

/**
 * {@see OrphanedGroupWordAudit} — api-surface-coherence ticket 143.
 *
 * The load-bearing cases are the last two. A declared word the host does not spell is `Warn`, never
 * `Fail`: whether a word resolves is a fact about the host, and a package shipping `group: 'Settings'`
 * into a host with no such group is a default the host declined, not a defect to block on. And a rung-1
 * placement must NOT silence the row — that is the exact shape 143 was filed to keep visible: the
 * flagship placed `open-api-specs` by assignment (ticket 137) while tower's class kept spelling
 * `Compliance`, and every other instrument read that as fixed.
 */
class OrphanedGroupWordAuditTest extends TestCase
{
    private ParticleResourceRegistry $resources;

    private GroupRegistry $groups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resources = new ParticleResourceRegistry;
        $this->groups = new GroupRegistry($this->resources);
    }

    private function declare(string $key, ?string $group): void
    {
        $this->resources->register(new ParticleResource(
            key: $key,
            backing: BeamParticle::class,
            data: 'Acme\\Data\\'.ucfirst($key).'Data',
            frame: false,
            group: $group,
        ));
    }

    private function audit(): OrphanedGroupWordAudit
    {
        return new OrphanedGroupWordAudit($this->groups, $this->resources);
    }

    public function test_no_declared_group_is_inconclusive_rather_than_clean(): void
    {
        $this->declare('widgets', null);

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertSame(OrphanedGroupWordAudit::CHECK, $findings[0]->check);
    }

    public function test_a_host_with_no_taxonomy_is_inconclusive_rather_than_all_orphans(): void
    {
        $this->declare('widgets', 'Widgets');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertFalse($findings[0]->conclusive);
        $this->assertStringContainsString('No API groups are registered', $findings[0]->detail);
    }

    public function test_words_that_resolve_by_key_name_or_nav_label_pass(): void
    {
        $this->groups->registerGroups(
            new ApiGroup(key: 'widgets', name: 'Widgets'),
            new ApiGroup(key: 'knowledge-graph', name: 'Knowledge Graph', navLabel: 'Graph'),
        );
        $this->declare('widgets', 'widgets');
        $this->declare('sprockets', 'Widgets');
        $this->declare('concepts', 'Graph');
        $this->declare('rules', 'knowledge graph');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertTrue($findings[0]->conclusive);
        $this->assertStringContainsString('4 particle resources', $findings[0]->detail);
    }

    public function test_an_orphaned_word_warns_by_name_and_never_fails(): void
    {
        $this->groups->registerGroups(new ApiGroup(key: 'determination', name: 'Determination'));
        $this->declare('declarations', 'Compliance');
        $this->declare('evidence', 'Determination');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertNotSame(DoctorStatus::Fail, $findings[0]->status);
        $this->assertStringContainsString('Acme\\Data\\DeclarationsData (resource [declarations]) declares group [Compliance]', $findings[0]->detail);
        $this->assertStringContainsString('URI guess', $findings[0]->detail);
        $this->assertStringNotContainsString('[evidence]', $findings[0]->detail);
    }

    public function test_a_rung_one_placement_does_not_hide_the_orphaned_word(): void
    {
        $this->groups->registerGroups(new ApiGroup(key: 'determination', name: 'Determination'));
        $this->groups->assign('open-api-specs', 'determination');
        $this->declare('open-api-specs', 'Compliance');

        $findings = $this->audit()->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Warn, $findings[0]->status);
        $this->assertStringContainsString('declares group [Compliance]', $findings[0]->detail);
        $this->assertStringContainsString('places it under [determination]', $findings[0]->detail);
    }

    public function test_one_row_per_orphaned_declaration(): void
    {
        $this->groups->registerGroups(new ApiGroup(key: 'determination', name: 'Determination'));
        $this->declare('declarations', 'Compliance');
        $this->declare('evidence', 'Compliance');
        $this->declare('users', 'Settings');

        $findings = $this->audit()->run();

        $this->assertCount(3, $findings);
        foreach ($findings as $finding) {
            $this->assertSame(DoctorStatus::Warn, $finding->status);
        }
    }
}
