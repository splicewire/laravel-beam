<?php

namespace Splicewire\Beam\Tests\Source;

use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Source\SourceGrant;
use Splicewire\Beam\Source\SourceWriteGate;
use Splicewire\Beam\Source\SourceWriteTier;

/**
 * The source write gate (ADR-0161 ticket 03) — the deny-by-default authority for source-originated
 * writes. Verified framework-free (pure logic): a source may write only its granted stems, only in
 * shadow tiers, and NO non-source actor (a real user, or null) can write through it — the
 * privilege-escalation guard the whole shadow model depends on.
 */
class SourceWriteGateTest extends TestCase
{
    private function gate(): SourceWriteGate
    {
        return new SourceWriteGate;
    }

    public function test_a_grant_permits_only_its_allow_listed_stems(): void
    {
        $grant = SourceGrant::for('federation:grant-42', ['content/article']);

        $this->assertTrue($this->gate()->authorizes('content/article', [], $grant));
        $this->assertFalse($this->gate()->authorizes('admin/user', [], $grant));
    }

    public function test_a_versioned_id_matches_a_stem_allow_list_entry(): void
    {
        $grant = SourceGrant::for('conduit:numero', ['content/article']);

        $this->assertTrue($this->gate()->authorizes('content/article/3', [], $grant));
    }

    public function test_a_null_actor_is_denied(): void
    {
        // The guest case: no actor → deny (never fall through as anonymous).
        $this->assertFalse($this->gate()->authorizes('content/article', [], null));
    }

    public function test_a_real_user_actor_is_denied_no_escalation(): void
    {
        // The escalation case: a non-SourceGrant actor (a user) can NEVER write through this gate,
        // so a source write can't borrow a user's policy scope to "shadow anything".
        $user = new class
        {
            public int $id = 1;
        };

        $this->assertFalse($this->gate()->authorizes('content/article', [], $user));
    }

    public function test_default_grant_with_no_stems_denies_everything(): void
    {
        $this->assertFalse($this->gate()->authorizes('content/article', [], SourceGrant::none()));
    }

    public function test_grant_permits_reports_membership_deny_by_default(): void
    {
        $grant = SourceGrant::for('s', ['content/article', 'taxonomy/tag']);

        $this->assertTrue($grant->permits('taxonomy/tag'));
        $this->assertFalse($grant->permits('taxonomy/silo'));
        $this->assertFalse(SourceGrant::none()->permits('anything'));
    }

    public function test_a_source_may_write_shadow_tiers_never_local(): void
    {
        $grant = SourceGrant::for('s', ['content/article']);

        $this->assertTrue($grant->mayWriteTier(SourceWriteTier::ShadowCached));
        $this->assertTrue($grant->mayWriteTier(SourceWriteTier::ShadowPinned));
        $this->assertFalse($grant->mayWriteTier(SourceWriteTier::Local));
    }
}
