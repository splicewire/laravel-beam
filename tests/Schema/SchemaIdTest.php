<?php

namespace Splicewire\Beam\Tests\Schema;

use Schemastud\JsonNs\NamespaceUri;
use Splicewire\Beam\Schema\SchemaId;
use Splicewire\Beam\Tests\TestCase;

/**
 * Isolation unit tests for the {@see SchemaId} value object — beam's role over the one
 * fleet-wide namespace-URI grammar `<base>/<name>/<version>` (ADR-0191: `SchemaId`
 * extends {@see NamespaceUri}).
 *
 * The object is pure (no container, no DB): it parses, formats, extracts the stem
 * and version, derives a sibling version, and answers same-stem comparability. It
 * is total — a malformed or unversioned tail yields an absent version rather than
 * throwing.
 *
 * Homed into beam-core from the app suite (recohere T15): `SchemaId` is a
 * `Splicewire\Beam\Schema\*` symbol, so its unit test belongs in beam-core.
 *
 * ITS `$id` LITERALS ARE DELIBERATELY NOT {@see TestCase::SCHEMA_AUTHORITY}. This class parses
 * strings; it mints nothing, so it is not asserting about the authority this suite serves under.
 *
 * Its origin was ALSO a bare, path-less example on purpose, because `name()` used to strip the
 * scheme and host only — so a PATH-shaped base (what 8 of the estate's 9 declaring roots use) left
 * the base path glued to the front of the name. Ticket 85 sighted that and left the fixtures
 * path-less rather than writing the bug into the expectations. **Ticket 113 fixed the method**:
 * `name()` now takes the declared authority, so both shapes are asserted here directly.
 */
class SchemaIdTest extends TestCase
{
    public function test_parses_and_round_trips_a_well_formed_versioned_id(): void
    {
        $id = SchemaId::from('https://schemas.example.test/content/article/3');

        $this->assertSame('https://schemas.example.test/content/article/3', (string) $id);
        $this->assertSame('https://schemas.example.test/content/article', $id->stem());
        $this->assertSame('content/article', $id->name('https://schemas.example.test'));
        $this->assertSame(3, $id->version());
    }

    public function test_extracts_the_name_as_the_stem_sans_the_base_authority(): void
    {
        $id = SchemaId::from('https://schemas.example.test/determination/merchant-declaration/1');

        $this->assertSame('https://schemas.example.test/determination/merchant-declaration', $id->stem());
        $this->assertSame('determination/merchant-declaration', $id->name('https://schemas.example.test'));
        $this->assertSame(1, $id->version());
    }

    /**
     * The shape 8 of the estate's 9 declaring roots actually use (ticket 113). The old
     * strip-to-first-slash implementation answered `schemas/content/cell` here.
     */
    public function test_extracts_the_name_under_a_PATH_shaped_authority(): void
    {
        $id = SchemaId::from('https://app.splicewire.com/schemas/content/cell/2');

        $this->assertSame('content/cell', $id->name('https://app.splicewire.com/schemas'));
        $this->assertSame('content/cell', $id->name('https://app.splicewire.com/schemas/'));
    }

    /**
     * An `$id` minted under someone else's authority cannot have its path split known from here, so
     * only `scheme://host` comes off. Honest, not correct — see the method docblock and ticket 107.
     */
    public function test_a_foreign_authority_falls_back_to_stripping_the_origin_only(): void
    {
        $id = SchemaId::from('https://audiostud.io/schemas/commerce/money/1');

        $this->assertSame('schemas/commerce/money', $id->name('https://fable.pub/schemas'));
    }

    /**
     * `base_uri` is tri-state and a host may legally have declared nothing. Taking the foreign
     * branch is the only answer available, and it must not throw — a value object parsing a string
     * is the wrong place for a host-composition verdict.
     */
    public function test_an_undeclared_or_opted_out_authority_takes_the_foreign_branch(): void
    {
        $id = SchemaId::from('https://schemas.example.test/content/article/3');

        $this->assertSame('content/article', $id->name(null));
        $this->assertSame('content/article', $id->name(false));
        $this->assertSame('content/article', $id->name(''));
    }


    public function test_derives_a_sibling_at_a_different_version_preserving_the_stem(): void
    {
        $id = SchemaId::from('https://schemas.example.test/content/article/3');

        $next = $id->withVersion(4);

        $this->assertSame('https://schemas.example.test/content/article/4', (string) $next);
        $this->assertSame($id->stem(), $next->stem());
        $this->assertSame(4, $next->version());
    }

    public function test_treats_two_ids_on_the_same_stem_as_comparable(): void
    {
        $a = SchemaId::from('https://schemas.example.test/content/article/1');
        $b = SchemaId::from('https://schemas.example.test/content/article/9');

        $this->assertTrue($a->isComparableTo($b));
        $this->assertTrue($b->isComparableTo($a));
    }

    public function test_treats_ids_on_different_stems_as_non_comparable(): void
    {
        $a = SchemaId::from('https://schemas.example.test/content/article/1');
        $b = SchemaId::from('https://schemas.example.test/content/author/1');

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
        $id = SchemaId::from('https://schemas.example.test/content/article/draft');

        $this->assertNull($id->version());
        $this->assertSame('https://schemas.example.test/content/article', $id->stem());
        $this->assertSame('https://schemas.example.test/content/article/draft', (string) $id);
    }

    public function test_a_schema_id_is_a_namespace_uri(): void
    {
        $id = SchemaId::from('https://schemas.example.test/content/article/3');

        $this->assertInstanceOf(NamespaceUri::class, $id);

        // Late static binding: parsing and sibling derivation stay in the SchemaId role.
        $this->assertInstanceOf(SchemaId::class, $id);
        $this->assertInstanceOf(SchemaId::class, $id->withVersion(4));
    }

    public function test_inherited_equality_and_pinnedness_primitives_are_available(): void
    {
        $pinned = SchemaId::from('https://schemas.example.test/content/article/3');
        $stem = SchemaId::from('https://schemas.example.test/content/article');

        $this->assertTrue($pinned->isPinned());
        $this->assertFalse($stem->isPinned());
        $this->assertTrue($stem->isUnpinned());

        // URI-is-identity equality: a stem and a pin are distinct; same raw is equal.
        $this->assertFalse($pinned->equals($stem));
        $this->assertTrue($pinned->equals(SchemaId::from('https://schemas.example.test/content/article/3')));
    }

    public function test_agrees_with_the_namespace_uri_parser_on_stem_and_version(): void
    {
        foreach ([
            'https://schemas.example.test/content/article/3',
            'https://schemas.example.test/content/article',
            'content-only',
            'https://schemas.example.test/content/article/draft',
        ] as $raw) {
            $id = SchemaId::from($raw);
            $uri = NamespaceUri::from($raw);

            $this->assertSame($uri->stem(), $id->stem());
            $this->assertSame($uri->version(), $id->version());
            $this->assertSame((string) $uri, (string) $id);
        }

        // isComparableTo() is the historical spelling of sameStemAs() — they agree.
        $a = 'https://schemas.example.test/content/article/1';
        $b = 'https://schemas.example.test/content/article/9';
        $c = 'https://schemas.example.test/content/author/1';

        $this->assertSame(
            NamespaceUri::from($a)->sameStemAs(NamespaceUri::from($b)),
            SchemaId::from($a)->isComparableTo(SchemaId::from($b)),
        );
        $this->assertSame(
            NamespaceUri::from($a)->sameStemAs(NamespaceUri::from($c)),
            SchemaId::from($a)->isComparableTo(SchemaId::from($c)),
        );
    }
}
