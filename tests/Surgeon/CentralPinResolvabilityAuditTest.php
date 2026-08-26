<?php

namespace Splicewire\Beam\Tests\Surgeon;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Surgeon\CentralPinJustificationAudit;
use Splicewire\Beam\Surgeon\CentralPinResolvabilityAudit;
use Splicewire\Beam\Tests\TestCase;

/**
 * beam-facade ticket 97 — the central-pin RESOLVABILITY audit.
 *
 * The load-bearing test in here is {@see test_it_fails_every_pin_when_the_connection_is_undefined()}, and
 * the reason is stated on the ticket: ticket 96 landed the alias that makes the whole estate's population
 * green, so this check will ship having never been seen firing against a real defect. A fixture that FAILS
 * is therefore the only evidence the failing branch works at all — an estate run that passes proves the
 * passing branch and nothing else.
 *
 * Sources are written into a fresh temp root rather than committed as fixture files, because the census
 * this audit composes EXCLUDES `tests/` and `fixtures/` paths by design — a committed fixture under
 * `tests/Surgeon/` would be invisible to the very code these tests drive.
 */
class CentralPinResolvabilityAuditTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null && is_dir($this->root)) {
            foreach ((array) glob($this->root.'/*.php') as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->root);
        }

        parent::tearDown();
    }

    /**
     * A census over a fresh scan root holding `name => source`, deliberately named to dodge the census's
     * own `tests`/`fixtures` path exclusions.
     *
     * @param  array<string, string>  $files
     */
    private function census(array $files): CentralPinJustificationAudit
    {
        $this->root = sys_get_temp_dir().'/central-pin-resolve-'.getmypid().'-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);

        foreach ($files as $name => $source) {
            file_put_contents($this->root.'/'.$name, $source);
        }

        return new CentralPinJustificationAudit([$this->root]);
    }

    /**
     * @param  list<string>  $defined  The connection names this fictional host defines.
     */
    private function audit(CentralPinJustificationAudit $census, array $defined): CentralPinResolvabilityAudit
    {
        return new CentralPinResolvabilityAudit(
            $census,
            fn (string $connection) => in_array($connection, $defined, true),
        );
    }

    private const PROPERTY_PIN = <<<'PHP'
        <?php
        namespace App\Models;
        class Ledger extends Model
        {
            protected $connection = 'central';
        }
        PHP;

    private const CONSTANT_PIN = <<<'PHP'
        <?php
        namespace App\Themes;
        class ThemeResolver
        {
            public const CENTRAL_CONNECTION = 'central';

            public function resolve()
            {
                return Theme::on(self::CENTRAL_CONNECTION)->get();
            }
        }
        PHP;

    private const CALL_PIN = <<<'PHP'
        <?php
        namespace App\Reads;
        class Promoter
        {
            public function read()
            {
                return Entry::on('central')->get();
            }
        }
        PHP;

    // ── the failing branch: the fixture the ticket asked for ────────────────────────────────────────

    /**
     * The defect 79 repaired, reproduced: pins exist, the connection does not. Every pin is reported, at
     * `fail`, because every one of them throws the moment it is touched.
     */
    public function test_it_fails_every_pin_when_the_connection_is_undefined(): void
    {
        $audit = $this->audit(
            $this->census(['Ledger.php' => self::PROPERTY_PIN, 'ThemeResolver.php' => self::CONSTANT_PIN]),
            ['mysql'],
        );

        $findings = $audit->run();

        $this->assertCount(2, $findings);

        foreach ($findings as $finding) {
            $this->assertSame(DoctorStatus::Fail, $finding->status);
            $this->assertSame(CentralPinResolvabilityAudit::CHECK, $finding->check);
        }
    }

    /**
     * The acceptance criterion, literally: file, pin FORM and connection name in the finding — and all
     * three pin forms reach it, because the census's whole reason for existing is that a pin does not
     * always look like a pin.
     */
    public function test_a_finding_names_the_file_the_form_and_the_connection(): void
    {
        $audit = $this->audit(
            $this->census([
                'Ledger.php' => self::PROPERTY_PIN,
                'ThemeResolver.php' => self::CONSTANT_PIN,
                'Promoter.php' => self::CALL_PIN,
            ]),
            [],
        );

        $details = array_map(fn ($finding) => $finding->detail, $audit->run());
        $joined = implode("\n", $details);

        $this->assertCount(3, $details);

        foreach (['Ledger.php', 'ThemeResolver.php', 'Promoter.php'] as $file) {
            $this->assertStringContainsString($file, $joined);
        }

        foreach ([
            CentralPinJustificationAudit::FORM_PROPERTY,
            CentralPinJustificationAudit::FORM_CONSTANT,
            CentralPinJustificationAudit::FORM_CALL,
        ] as $form) {
            $this->assertStringContainsString($form.' form', $joined);
        }

        foreach ($details as $detail) {
            $this->assertStringContainsString('['.CentralPinJustificationAudit::CENTRAL.']', $detail);
        }
    }

    /** One cause, N casualties — the finding says so, because a reader seeing twenty must not do twenty repairs. */
    public function test_the_finding_names_the_single_shared_repair(): void
    {
        $audit = $this->audit($this->census(['Ledger.php' => self::PROPERTY_PIN]), []);

        $this->assertStringContainsString('ONE cause with one repair', $audit->run()[0]->detail);
    }

    // ── the passing branch ──────────────────────────────────────────────────────────────────────────

    /** A pin whose connection resolves produces nothing — a single pass, not a per-pin all-clear. */
    public function test_it_passes_when_the_connection_is_defined(): void
    {
        $audit = $this->audit(
            $this->census(['Ledger.php' => self::PROPERTY_PIN, 'ThemeResolver.php' => self::CONSTANT_PIN]),
            ['mysql', 'central'],
        );

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('2 pin(s)', $findings[0]->detail);
        $this->assertSame([], $audit->unresolvable());
    }

    /**
     * The acceptance criterion that the alias must silence this: a host that defines no `central` block of
     * its own but installs beam-core still passes, because `registerCentralConnectionAlias()` (ticket 96)
     * has copied the default connection onto that name by the time any audit runs. Driven through the LIVE
     * config rather than the test seam — the seam cannot witness the alias, and the alias is the claim.
     */
    public function test_the_alias_registered_by_beam_core_is_enough_to_resolve_a_pin(): void
    {
        $default = (string) config('database.default');

        $this->assertNotSame('central', $default);
        $this->assertSame(
            config("database.connections.{$default}"),
            config('database.connections.central'),
            'The `central` block must be the ALIAS the provider copied, not one the harness declared — '.
            'otherwise this test witnesses the harness rather than ticket 96.',
        );

        $audit = CentralPinResolvabilityAudit::forApp($this->census(['Ledger.php' => self::PROPERTY_PIN]));

        $this->assertTrue($audit->connectionIsDefined(CentralPinJustificationAudit::CENTRAL));
        $this->assertSame(DoctorStatus::Pass, $audit->run()[0]->status);
    }

    /**
     * The state the alias leaves behind, reached the only way a booted harness can reach it — by putting the
     * config where the provider's guarded no-op would have left it (`database.default` IS `central`, so
     * there is no block to copy FROM) and re-asking. This proves the audit reads LIVE config on every call
     * rather than sampling it once at construction, which is what makes it able to speak in that state at
     * all; it does not re-run the provider, and does not claim to.
     */
    public function test_it_still_fires_where_the_alias_deliberately_declines(): void
    {
        config(['database.default' => 'central']);
        config(['database.connections.central' => null]);

        $audit = CentralPinResolvabilityAudit::forApp($this->census(['Ledger.php' => self::PROPERTY_PIN]));

        $this->assertFalse($audit->connectionIsDefined(CentralPinJustificationAudit::CENTRAL));
        $this->assertSame(DoctorStatus::Fail, $audit->run()[0]->status);
    }

    // ── scope ───────────────────────────────────────────────────────────────────────────────────────

    /** No pins in scope is a pass, not an empty result — "unverified is not passed" cuts the other way too. */
    public function test_no_pins_in_scope_is_a_pass(): void
    {
        $audit = $this->audit($this->census([]), []);

        $findings = $audit->run();

        $this->assertCount(1, $findings);
        $this->assertSame(DoctorStatus::Pass, $findings[0]->status);
        $this->assertStringContainsString('nothing to resolve', $findings[0]->detail);
    }

    /**
     * The two audits must report over an identical population. A pin visible to the justification backlog
     * and invisible to the resolvability check (or the reverse) would be a scope bug that only shows up as
     * a host throwing on a model nobody ever flagged.
     */
    public function test_it_reports_over_exactly_the_justification_audit_population(): void
    {
        $census = $this->census([
            'Ledger.php' => self::PROPERTY_PIN,
            'ThemeResolver.php' => self::CONSTANT_PIN,
            'Promoter.php' => self::CALL_PIN,
        ]);

        $this->assertSame(
            array_column($census->pins(), 'file'),
            array_column($this->audit($census, [])->unresolvable(), 'file'),
        );
    }
}
