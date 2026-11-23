<?php

namespace Splicewire\Beam\Tests\Config;

use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Config\ConfigSectionDeclarations;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Doctor\ConfigSectionCoverageAudit;
use Splicewire\Beam\Tests\Fixtures\Config\DeclarationsSection;
use Splicewire\Beam\Tests\Fixtures\Config\DeclInheritedSecretCreds;
use Splicewire\Beam\Tests\Fixtures\Config\DeclInheritedSecretSection;
use Splicewire\Beam\Tests\Fixtures\Config\DeclRecursiveSection;
use Splicewire\Beam\Tests\Fixtures\Config\DeclSmtpTransport;
use Splicewire\Beam\Tests\Fixtures\Config\DeclUnreachedSection;
use Splicewire\Beam\Tests\Fixtures\Config\StickySecretSection;
use Splicewire\Beam\Tests\TestCase;

/**
 * The section audit's declaration findings (config-sections 22 + F1, 21, 17), on {@see DeclarationsSection} and
 * {@see DeclUnreachedSection}: each kind is reported at the key that declares it, once, and the controls beside them are
 * not reported.
 */
class ConfigSectionDeclarationsTest extends TestCase
{
    /**
     * @param  class-string  $section
     * @return array<string, list<string>> finding kinds, by pointer
     */
    private function kinds(string $section): array
    {
        $kinds = [];

        foreach (ConfigSectionDeclarations::findings($section) as $finding) {
            $kinds[$finding['pointer']][] = $finding['kind'];
        }

        ksort($kinds);

        return $kinds;
    }

    public function test_each_declaration_finding_is_reported_once_at_the_key_that_declares_it(): void
    {
        $this->assertSame([
            '/api_key' => [ConfigSectionDeclarations::KIND_SECRET_OPEN_TO_OPERATOR],
            '/relays/#/password' => [ConfigSectionDeclarations::KIND_SECRET_IN_LIST],
            '/vault' => [ConfigSectionDeclarations::KIND_SECRET_OPEN_TO_OPERATOR],
            '/vault/token' => [ConfigSectionDeclarations::KIND_SECRET_FALSE],
        ], $this->kinds(DeclarationsSection::class));
    }

    /** 22: secrecy is sticky, so `secret: false` there changes nothing but misleads; the walk still reads it secret. */
    public function test_an_explicit_secret_false_below_a_secret_is_reported_and_stays_secret(): void
    {
        $this->assertTrue(DeclarationsSection::keyDeclaration(['vault', 'token'])->secret);

        $message = collect(ConfigSectionDeclarations::findings(DeclarationsSection::class))->firstWhere('pointer', '/vault/token')['message'] ?? '';
        $this->assertStringContainsString('secret: false below a secret ancestor', $message);
    }

    /** 22: the original red's fixture declares its own `#[ConfigKey]` WITHOUT secret: false, which is not reported. */
    public function test_a_child_declaration_that_omits_secret_is_not_reported(): void
    {
        $this->assertArrayNotHasKey('/holder/creds/token', $this->kinds(StickySecretSection::class));
    }

    /** 21: the finding names the map form. */
    public function test_a_secret_in_a_list_names_the_map_form(): void
    {
        $message = collect(ConfigSectionDeclarations::findings(DeclarationsSection::class))->firstWhere('pointer', '/relays/#/password')['message'] ?? '';

        $this->assertStringContainsString('#[MapValues(Entry::class)]', $message);
    }

    /** F1: an interface-typed key whose loaded implementation declares a secret the walk cannot reach. */
    public function test_a_secret_the_walk_cannot_reach_is_reported_and_a_whole_secret_key_is_not(): void
    {
        $this->assertTrue(class_exists(DeclSmtpTransport::class), 'precondition: the implementation is loaded');

        $this->assertSame(['/transport' => [ConfigSectionDeclarations::KIND_SECRET_UNREACHED]], $this->kinds(DeclUnreachedSection::class));
    }

    /** Codex review, lane A (blocker 3): a record that recurses through a list of itself is read again inside the list. */
    public function test_a_secret_inside_a_recursive_list_is_reported(): void
    {
        $this->assertSame(
            ['/tree/children/#/password' => [ConfigSectionDeclarations::KIND_SECRET_IN_LIST]],
            $this->kinds(DeclRecursiveSection::class),
        );
    }

    /** Codex review, lane A (blocker 4): a subclass re-declaring an inherited plain property as secret is unreached. */
    public function test_a_subclass_that_redeclares_an_inherited_property_secret_is_reported(): void
    {
        $this->assertTrue(class_exists(DeclInheritedSecretCreds::class), 'precondition: the subclass is loaded');
        $this->assertFalse(DeclInheritedSecretSection::keyDeclaration(['creds', 'token'])->secret, 'precondition: the walk reads the declared type');

        $this->assertSame(['/creds' => [ConfigSectionDeclarations::KIND_SECRET_UNREACHED]], $this->kinds(DeclInheritedSecretSection::class));
    }

    public function test_the_section_coverage_audit_fails_a_secret_in_a_list_and_warns_the_rest(): void
    {
        config(['qa.decl' => ['vault' => ['token' => null, 'label' => null], 'relays' => [], 'api_key' => null, 'closed_key' => null, 'opened_key' => null]]);
        $this->app->instance(ConfigSectionRegistry::class, new ConfigSectionRegistry);
        app(ConfigSectionRegistry::class)->section(DeclarationsSection::class, owner: 'qa/fixture');

        $findings = array_values(array_filter(
            app(ConfigSectionCoverageAudit::class)->run(),
            fn (Finding $finding): bool => str_contains($finding->detail, 'qa.decl: /'),
        ));
        $by = [];

        foreach ($findings as $finding) {
            $by[$finding->status->name][] = $finding->detail;
        }

        $this->assertCount(1, $by[DoctorStatus::Fail->name] ?? [], implode("\n", $by[DoctorStatus::Fail->name] ?? []));
        $this->assertStringContainsString('qa.decl: /relays/#/password is a secret inside a list', $by[DoctorStatus::Fail->name][0]);
        $this->assertCount(3, $by[DoctorStatus::Warn->name] ?? [], implode("\n", $by[DoctorStatus::Warn->name] ?? []));
    }
}
