<?php

declare(strict_types=1);

namespace Splicewire\Beam\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Doctor\FamilySourceCoverageAudit;
use Splicewire\Beam\Doctor\FamilyTokenContractAudit;
use Splicewire\Beam\Doctor\Support\FamilyTailwindScan;
use Splicewire\Beam\Tests\TestCase;

class FamilyTokenContractAuditTest extends TestCase
{
    private FamilyTailwindHost $host;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = new FamilyTailwindHost;
    }

    private function finding(): Finding
    {
        $findings = (new FamilyTokenContractAudit(new FamilyTailwindScan($this->host->root)))->run();

        $this->assertCount(1, $findings);

        return $findings[0];
    }

    /** A host that wires the derivation plugin scans every resolved dist, so every one is in scope. */
    private function derivedHost(string $entryExtra = ''): void
    {
        $this->host->write('resources/css/app.css', "@import 'tailwindcss';\n".$entryExtra);
        $this->host->write('vite.config.ts', "import { familySources } from '@schemastud/seam/vite';\n");
    }

    public function test_an_undeclared_token_is_a_warning_never_a_failure(): void
    {
        $this->derivedHost();
        $this->host->package('@splicewire/beam-ux', [
            'index.js' => 'const c = "flex items-center text-sidebar-active-foreground";',
        ]);

        $finding = $this->finding();

        // ⚠️ Warn, not Fail. "The host never renders that component" is a legitimate reading, so this
        // audit does NOT have the coverage audit's no-legitimate-reading property.
        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('--color-sidebar-active-foreground', $finding->detail);
        $this->assertStringContainsString('referenced by @splicewire/beam-ux', $finding->detail);
    }

    public function test_a_token_declared_in_the_hosts_theme_closes_the_finding(): void
    {
        $this->derivedHost("@theme {\n  --color-sidebar-active-foreground: #fff;\n}\n");
        $this->host->package('@splicewire/beam-ux', [
            'index.js' => 'const c = "flex text-sidebar-active-foreground";',
        ]);

        $this->assertSame(DoctorStatus::Pass, $this->finding()->status);
    }

    /**
     * The flagship's sidebar contract lives in `resources/css/brand-tokens.css`, not in the entry. An
     * audit that stops at the entry reports the whole contract missing.
     */
    public function test_declarations_are_followed_through_relative_imports(): void
    {
        $this->derivedHost("@import './brand-tokens.css';\n");
        $this->host->write('resources/css/brand-tokens.css', "@theme {\n  --color-sidebar-ring: #000;\n}\n");
        $this->host->package('@schemastud/ui', ['index.js' => 'const c = "rounded ring-sidebar-ring";']);

        $this->assertSame(DoctorStatus::Pass, $this->finding()->status);
    }

    /** A custom property OUTSIDE `@theme` is a value, not a Tailwind namespace entry — no utility. */
    public function test_a_root_declaration_outside_theme_does_not_declare_a_token(): void
    {
        $this->derivedHost(":root {\n  --color-sidebar-ring: #000;\n}\n");
        $this->host->package('@schemastud/ui', ['index.js' => 'const c = "rounded ring-sidebar-ring";']);

        $this->assertSame(DoctorStatus::Warn, $this->finding()->status);
    }

    /** The check only closes because the built-in palette is a fixed, enumerable list. */
    public function test_built_in_palette_colours_are_not_findings(): void
    {
        $this->derivedHost();
        $this->host->package('@schemastud/ui', [
            'index.js' => 'const c = "bg-red-500 text-white/80 border-slate-200 hover:ring-transparent";',
        ]);

        $this->assertSame(DoctorStatus::Pass, $this->finding()->status);
    }

    /**
     * The noise the first prototype ran at ~50%: font sizes, bare side utilities, SVG/CSS property
     * names, and — the last one standing — an event-name constant alone in a string literal
     * (`"text-delta"` in `@schemastud/chat`), which no static rule can tell from a class.
     */
    public function test_non_colour_utilities_and_lone_literals_are_not_findings(): void
    {
        $this->derivedHost();
        $this->host->package('@schemastud/ui', [
            'index.js' => 'const c = "text-base border-t divide-y shadow-lg stroke-width text-ellipsis bg-cover";'
                ."\nconst e = \"text-delta\";\nconst f = 'stroke-width';",
        ]);

        $this->assertSame(DoctorStatus::Pass, $this->finding()->status);
    }

    /** A `dist/*.css` carries CSS property names, not classes the host has to generate. */
    public function test_a_dists_own_stylesheet_is_not_scanned(): void
    {
        $this->derivedHost();
        $this->host->package('@schemastud/ui', ['style.css' => '.a { stroke-width: 2px; border-sidebar-ring: 0; }']);

        $this->assertSame(DoctorStatus::Pass, $this->finding()->status);
    }

    /**
     * A package outside every `@source` glob cannot emit a class at all. Reporting its tokens would
     * double-count {@see FamilySourceCoverageAudit}'s own finding.
     */
    public function test_an_unscanned_dist_is_out_of_scope(): void
    {
        $this->host->write('resources/css/app.css', "@import 'tailwindcss';\n");
        $this->host->package('@schemastud/ui', ['index.js' => 'const c = "flex ring-sidebar-ring";']);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('no family dist is inside an @source glob', $finding->detail);
    }

    public function test_a_host_with_no_tailwind_v4_entry_is_out_of_the_population(): void
    {
        $this->host->package('@schemastud/ui', ['index.js' => 'const c = "flex ring-sidebar-ring";']);

        $this->assertStringContainsString('out of the audit', $this->finding()->detail);
    }

    /** Variants and the `/40` opacity modifier reference the same token as the bare utility. */
    public function test_variants_and_opacity_modifiers_resolve_to_the_bare_token(): void
    {
        $this->derivedHost();
        $this->host->package('@splicewire/beam-ux', [
            'index.js' => 'const c = "gap-2 dark:hover:bg-sidebar-accent/40";',
        ]);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('--color-sidebar-accent  ', $finding->detail);
    }
}
