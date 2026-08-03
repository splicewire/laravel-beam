<?php

namespace Splicewire\Beam\Tests\Schema;

use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Tests\TestCase;

/**
 * Isolation unit tests for the {@see SchemaId} value object — the single source of
 * truth for the absolute versioned schema `$id` grammar `<base>/<name>/<version>`.
 *
 * The object is pure (no container, no DB): it parses, formats, extracts the stem
 * and version, derives a sibling version, and answers same-stem comparability. It
 * is total — a malformed or unversioned tail yields an absent version rather than
 * throwing.
 *
 * Homed into beam-core from the app suite (recohere T15): `SchemaId` is a
 * `Splicewire\Beam\Schema\*` symbol, so its unit test belongs in beam-core.
 */
class SchemaIdTest extends TestCase
{
    public function test_parses_and_round_trips_a_well_formed_versioned_id(): void
    {
        $id = SchemaId::from('https://schemas.splicewire.app/content/article/3');

        $this->assertSame('https://schemas.splicewire.app/content/article/3', (string) $id);
        $this->assertSame('https://schemas.splicewire.app/content/article', $id->stem());
        $this->assertSame('content/article', $id->name());
        $this->assertSame(3, $id->version());
    }

    public function test_extracts_the_name_as_the_stem_sans_the_base_authority(): void
    {
        $id = SchemaId::from('https://schemas.splicewire.app/compliance/merchant-declaration/1');

        $this->assertSame('https://schemas.splicewire.app/compliance/merchant-declaration', $id->stem());
        $this->assertSame('compliance/merchant-declaration', $id->name());
        $this->assertSame(1, $id->version());
    }

    public function test_derives_a_sibling_at_a_different_version_preserving_the_stem(): void
    {
        $id = SchemaId::from('https://schemas.splicewire.app/content/article/3');

        $next = $id->withVersion(4);

        $this->assertSame('https://schemas.splicewire.app/content/article/4', (string) $next);
        $this->assertSame($id->stem(), $next->stem());
        $this->assertSame(4, $next->version());
    }

    public function test_treats_two_ids_on_the_same_stem_as_comparable(): void
    {
        $a = SchemaId::from('https://schemas.splicewire.app/content/article/1');
        $b = SchemaId::from('https://schemas.splicewire.app/content/article/9');

        $this->assertTrue($a->isComparableTo($b));
        $this->assertTrue($b->isComparableTo($a));
    }

    public function test_treats_ids_on_different_stems_as_non_comparable(): void
    {
        $a = SchemaId::from('https://schemas.splicewire.app/content/article/1');
        $b = SchemaId::from('https://schemas.splicewire.app/content/author/1');

        $this->assertFalse($a->isComparableTo($b));
        $this->assertFalse($b->isComparableTo($a));
    }

    public function test_tolerates_an_unversioned_tail_by_reporting_an_absent_version(): void
    {
        $id = SchemaId::from('content-only');

        $this->assertNull($id->version());
        $this->assertSame('content-only', $id->stem());
        $this->assertSame('content-only', (string) $id);
    }

    public function test_tolerates_a_malformed_non_integer_version_tail_without_throwing(): void
    {
        $id = SchemaId::from('https://schemas.splicewire.app/content/article/draft');

        $this->assertNull($id->version());
        $this->assertSame('https://schemas.splicewire.app/content/article', $id->stem());
        $this->assertSame('https://schemas.splicewire.app/content/article/draft', (string) $id);
    }
}
